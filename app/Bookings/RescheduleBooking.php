<?php

namespace App\Bookings;

use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Scheduling\AvailableSlots;
use App\Scheduling\LocalTime;
use App\Scheduling\Slot;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A customer or an admin moves a booking to another time with the same
 * employee and service (ISSUE-17). No HTTP here; BookingController calls
 * it with resolved models.
 *
 * Atomic: the booking row is updated in place, inside one transaction, and
 * only after every check on the new time passed. Until the commit the old
 * time stays taken by the booking; a refusal throws, the transaction rolls
 * back and nothing changes. There is never a moment in which the booking
 * holds both times, or neither.
 *
 * Refused, checked in this order:
 * - RescheduleRefused: BookingPolicy::reschedule() denies it (same rules
 *   and deadline as cancelling), judged on the locked, fresh row;
 * - BookingRefused, as CreateBooking does for a new booking:
 *   1. the service is inactive or the employee can no longer perform it;
 *   2. the new start is the current one;
 *   3. the employee has another booking overlapping the new time, buffers
 *      included;
 *   4. the customer has another appointment overlapping the new time;
 *   5. the new start is not an offered slot (AvailableSlots).
 *   The booking's own old time does not count in 3-5, so it can move to a
 *   slot that overlaps its old time (e.g. 15 minutes later).
 *
 * The booking keeps its duration and price snapshot (docs/erd.md); the
 * buffer is the service's current one, as AvailableSlots uses it. Status
 * and notes are kept; the reminder is reset so the new time gets one.
 *
 * Locks (docs/erd.md "Concurrency"): employee, then customer, as in
 * CreateBooking, then the booking row. A new booking and a move for the
 * same employee or customer therefore wait for each other, and a
 * cancellation of the same booking (which locks only the booking) waits
 * for the move or the other way round. The order never forms a cycle.
 */
final readonly class RescheduleBooking
{
    public function __construct(
        private AvailableSlots $availableSlots,
    ) {}

    /**
     * @param  CarbonImmutable  $startsAt  the new start, any time zone
     *
     * @throws RescheduleRefused when the policy denies it; a booking the user
     *                           may not see is refused as not found
     * @throws BookingRefused when the new time is not free
     */
    public function handle(User $by, Booking $booking, CarbonImmutable $startsAt): Booking
    {
        $startsAt = $startsAt->utc();

        return DB::transaction(function () use ($by, $booking, $startsAt): Booking {
            Employee::query()->whereKey($booking->employee_id)->lockForUpdate()->value('id');
            User::query()->whereKey($booking->customer_id)->lockForUpdate()->value('id');
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            $decision = Gate::forUser($by)->inspect('reschedule', $locked);
            if ($decision->denied()) {
                throw RescheduleRefused::because(
                    $decision->message() ?? 'Deze afspraak kan niet worden verplaatst.',
                    notFound: $decision->status() === 404,
                );
            }

            /** @var Service $service */
            $service = $locked->service()->firstOrFail();
            /** @var Employee $employee */
            $employee = $locked->employee()->firstOrFail();
            /** @var User $customer */
            $customer = $locked->customer()->firstOrFail();

            if (! $service->is_active || ! $employee->canPerform($service)) {
                throw BookingRefused::notBookable();
            }

            if ($locked->starts_at->equalTo($startsAt)) {
                throw BookingRefused::sameSlot();
            }

            $timing = $this->timing($locked, $service);

            $endsAt = $startsAt->addMinutes($timing->duration_minutes);
            $blockedUntil = $endsAt->addMinutes($timing->buffer_minutes);

            if ($employee->bookings()->blockingBetween($startsAt, $blockedUntil)->whereKeyNot($locked->getKey())->exists()) {
                throw BookingRefused::employeeBusy();
            }

            if (Booking::query()->attendedByBetween($customer, $startsAt, $endsAt)->whereKeyNot($locked->getKey())->exists()) {
                throw BookingRefused::customerBusy(toCustomer: $by->is($customer));
            }

            $slot = $this->offeredSlot($timing, $employee, $startsAt, $locked);
            if ($slot === null) {
                throw BookingRefused::notOffered();
            }

            $locked->starts_at = $slot->startsAt;
            $locked->ends_at = $slot->endsAt;
            $locked->blocked_until = $slot->blockedUntil;
            $locked->setAttribute('reminder_sent_at', null);
            $locked->save();

            return $locked;
        });
    }

    /**
     * The times the booking can move to on a local date, for the page
     * (ISSUE-17): the slots of its service and employee, with its own old
     * time counted as free and its current start left out. These are the
     * same slots handle() accepts, minus the other overlap checks there
     * (the customer's other appointments).
     *
     * @return list<Slot>
     */
    public function slotsFor(Booking $booking, DateTimeInterface $localDate): array
    {
        /** @var Service $service */
        $service = $booking->service()->firstOrFail();
        /** @var Employee $employee */
        $employee = $booking->employee()->firstOrFail();

        $slots = $this->availableSlots->for($this->timing($booking, $service), $employee, $localDate, ignoring: $booking);

        return array_values(array_filter(
            $slots,
            fn (Slot $slot): bool => ! $slot->startsAt->equalTo($booking->starts_at),
        ));
    }

    /**
     * The service as the slot calculation needs it for this booking: with
     * the booked duration, not the service's current one (snapshot,
     * docs/erd.md). An unsaved copy; the service itself is not changed.
     */
    private function timing(Booking $booking, Service $service): Service
    {
        $timing = clone $service;
        $timing->duration_minutes = $booking->duration_minutes;

        return $timing;
    }

    private function offeredSlot(Service $service, Employee $employee, CarbonImmutable $startsAt, Booking $moving): ?Slot
    {
        $slots = $this->availableSlots->for($service, $employee, LocalTime::toLocal($startsAt), ignoring: $moving);

        foreach ($slots as $slot) {
            if ($slot->startsAt->equalTo($startsAt)) {
                return $slot;
            }
        }

        return null;
    }
}
