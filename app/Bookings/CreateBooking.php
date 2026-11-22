<?php

namespace App\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Scheduling\AvailableSlots;
use App\Scheduling\DutchHolidays;
use App\Scheduling\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A customer books a free slot (ISSUE-12). No HTTP here; BookingController
 * calls it with resolved models.
 *
 * Refused (BookingRefused) when, checked in this order:
 * 1. the service is inactive or the employee cannot perform it;
 * 2. the employee already has a requested/confirmed booking overlapping
 *    starts_at up to blocked_until, buffers included (Booking::blockingBetween);
 * 3. the customer already has a requested/confirmed appointment, with any
 *    employee, overlapping starts_at up to ends_at; the buffer is the
 *    employee's time, not the customer's (Booking::attendedByBetween);
 * 4. the start is not one of the slots AvailableSlots offers: outside the
 *    working time, off the interval grid, too long for the block, or in
 *    the past.
 *
 * Checks 2 and 3 come before 4 so the customer gets the specific reason;
 * AvailableSlots would refuse an employee overlap as well.
 *
 * Checks and insert run in one transaction. Serialising simultaneous
 * requests for the same employee (lockForUpdate on the employee row,
 * docs/erd.md "Concurrency") is ISSUE-13.
 */
final readonly class CreateBooking
{
    public function __construct(
        private AvailableSlots $availableSlots,
    ) {}

    /**
     * @param  CarbonImmutable  $startsAt  the chosen start, any time zone
     *
     * @throws BookingRefused
     */
    public function handle(User $customer, Service $service, Employee $employee, CarbonImmutable $startsAt, ?string $notes = null): Booking
    {
        if (! $service->is_active || ! $employee->canPerform($service)) {
            throw BookingRefused::notBookable();
        }

        $startsAt = $startsAt->utc();
        $endsAt = $startsAt->addMinutes($service->duration_minutes);
        $blockedUntil = $endsAt->addMinutes($service->buffer_minutes);

        return DB::transaction(function () use ($customer, $service, $employee, $startsAt, $endsAt, $blockedUntil, $notes): Booking {
            if ($employee->bookings()->blockingBetween($startsAt, $blockedUntil)->exists()) {
                throw BookingRefused::employeeBusy();
            }

            if (Booking::query()->attendedByBetween($customer, $startsAt, $endsAt)->exists()) {
                throw BookingRefused::customerBusy();
            }

            $slot = $this->offeredSlot($service, $employee, $startsAt);
            if ($slot === null) {
                throw BookingRefused::notOffered();
            }

            $booking = new Booking;
            $booking->customer_id = $customer->id;
            $booking->employee_id = $employee->id;
            $booking->service_id = $service->id;
            $booking->starts_at = $slot->startsAt;
            $booking->ends_at = $slot->endsAt;
            $booking->blocked_until = $slot->blockedUntil;
            $booking->duration_minutes = $service->duration_minutes;
            $booking->price_cents = $service->price_cents;
            $booking->status = BookingStatus::Requested;
            $booking->notes = filled($notes) ? trim($notes) : null;
            $booking->save();

            return $booking;
        });
    }

    private function offeredSlot(Service $service, Employee $employee, CarbonImmutable $startsAt): ?Slot
    {
        $localDate = $startsAt->setTimezone(DutchHolidays::TIMEZONE);

        foreach ($this->availableSlots->for($service, $employee, $localDate) as $slot) {
            if ($slot->startsAt->equalTo($startsAt)) {
                return $slot;
            }
        }

        return null;
    }
}
