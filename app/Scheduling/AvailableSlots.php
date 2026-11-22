<?php

namespace App\Scheduling;

use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Free slots for a service with an employee on a local date (ISSUE-11).
 *
 * Loads what SlotCalculator needs: the day schedule (weekly hours, holidays,
 * exceptions; see DaySchedule) and the employee's blocking bookings that
 * day, whatever service they are for. No HTTP here: a controller (ISSUE-15)
 * resolves the models and calls for().
 */
final readonly class AvailableSlots
{
    public function __construct(
        private int $intervalMinutes,
    ) {}

    public static function fromConfig(): self
    {
        return new self((int) config('booking.slot_interval', 15));
    }

    /**
     * Empty when the service is inactive or the employee cannot perform it
     * (Employee::canPerform()), so no slot is ever offered for a booking
     * that would be refused.
     *
     * @param  DateTimeInterface  $localDate  only the calendar date is used (Europe/Amsterdam)
     * @param  CarbonImmutable|null  $now  slots before this moment are left out; defaults to the current time
     * @return list<Slot>
     */
    public function for(Service $service, Employee $employee, DateTimeInterface $localDate, ?CarbonImmutable $now = null): array
    {
        if (! $service->is_active || ! $employee->canPerform($service)) {
            return [];
        }

        $schedule = $employee->scheduleOn($localDate);
        if (! $schedule->isAvailable()) {
            return [];
        }

        $ranges = $schedule->ranges;
        $from = SlotCalculator::toUtc($schedule->date, $ranges[0]->start);
        $until = SlotCalculator::toUtc($schedule->date, $ranges[array_key_last($ranges)]->end)
            ->addMinutes($service->buffer_minutes);

        $busy = $employee->bookings()
            ->blockingBetween($from, $until)
            ->get(['id', 'starts_at', 'blocked_until'])
            ->map(fn (Booking $booking): BusyPeriod => BusyPeriod::fromBooking($booking))
            ->values()
            ->all();

        return SlotCalculator::calculate(
            date: $schedule->date,
            workingRanges: $ranges,
            durationMinutes: $service->duration_minutes,
            bufferMinutes: $service->buffer_minutes,
            busy: $busy,
            intervalMinutes: $this->intervalMinutes,
            notBefore: $now ?? CarbonImmutable::now(),
        );
    }
}
