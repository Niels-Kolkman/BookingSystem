<?php

namespace App\Scheduling;

use App\Enums\BookingStatus;
use App\Enums\ScheduleExceptionType;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The agenda of one employee over a run of local dates (ISSUE-21): per day
 * the working hours and breaks (DaySchedule), time off and the bookings.
 * Reads with three queries whatever the number of days: working hours,
 * schedule exceptions in the range and bookings in the range.
 *
 * Cancelled bookings free the time and are left out. Who may see an agenda
 * is EmployeePolicy::viewAgenda(); this class does not check it.
 */
final class Agenda
{
    /** Shown when a day has nothing to draw. */
    public const DEFAULT_HOURS = [8 * 60, 18 * 60];

    /**
     * @param  CarbonImmutable  $from  first local date; only its calendar date is used
     * @param  int  $days  number of days, at least 1
     * @return list<AgendaDay>
     */
    public static function for(Employee $employee, CarbonImmutable $from, int $days): array
    {
        if ($days < 1) {
            throw new InvalidArgumentException('An agenda shows at least one day.');
        }

        $first = LocalTime::midnight($from);
        $last = $first->addDays($days - 1);

        $exceptions = $employee->scheduleExceptions()
            ->where('date', '>=', $first->format('Y-m-d'))
            ->where('date', '<=', $last->format('Y-m-d'))
            ->get();

        // DaySchedule reads the loaded relations; a copy keeps the caller's
        // model free of this partial list of exceptions.
        $subject = (clone $employee)->setRelation('scheduleExceptions', $exceptions);
        $subject->loadMissing('workingHours');

        $bookings = $employee->bookings()
            ->with(['customer', 'service'])
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->where('starts_at', '<', LocalTime::at($last, TimeRange::MINUTES_PER_DAY))
            ->where('ends_at', '>', $first->utc())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $first->addDays($i);
            $dayStart = LocalTime::midnight($day)->utc();
            $dayEnd = LocalTime::at($day, TimeRange::MINUTES_PER_DAY);

            $result[] = new AgendaDay(
                schedule: DaySchedule::for($subject, $day),
                timeOff: $exceptions
                    ->filter(fn (ScheduleException $e): bool => $e->type === ScheduleExceptionType::Unavailable && $e->date->isSameDay($day))
                    ->values()
                    ->all(),
                bookings: $bookings
                    ->filter(fn (Booking $b): bool => $b->starts_at < $dayEnd && $b->ends_at > $dayStart)
                    ->map(fn (Booking $b): AgendaBooking => AgendaBooking::onDay($b, $day))
                    ->values()
                    ->all(),
            );
        }

        return $result;
    }

    /**
     * The hours to show for these days, in minutes since local midnight:
     * from the whole hour before the earliest item to the whole hour after
     * the latest, so every day of a week shares the same rows.
     *
     * @param  list<AgendaDay>  $days
     * @return array{int, int}
     */
    public static function hourWindow(array $days): array
    {
        $ranges = array_merge(...array_map(fn (AgendaDay $day): array => $day->drawnRanges(), $days));

        if ($ranges === []) {
            return self::DEFAULT_HOURS;
        }

        $start = min(array_column($ranges, 0));
        $end = max(array_column($ranges, 1));

        return [intdiv($start, 60) * 60, (int) ceil($end / 60) * 60];
    }
}
