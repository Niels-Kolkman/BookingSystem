<?php

namespace App\Scheduling;

use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * How much of the working time of an employee is taken by bookings
 * (ISSUE-22): working minutes from DaySchedule, booked minutes the part of
 * those working minutes covered by a booking that is not cancelled.
 *
 * - Requested, confirmed, completed and no-show bookings all count: their
 *   time was reserved and could not be offered to anyone else. The no-show
 *   rate is a separate statistic.
 * - Only booked time inside working hours counts, and overlapping bookings
 *   count once, so the rate never exceeds 100%. The buffer after a booking
 *   is not counted, as in the agenda.
 * - Minutes are wall-clock minutes, as in the schedule and the agenda
 *   (decision 11), so the night the clock changes is off by at most an hour.
 */
final readonly class Occupancy
{
    public function __construct(
        public int $workingMinutes,
        public int $bookedMinutes,
    ) {}

    public static function none(): self
    {
        return new self(0, 0);
    }

    /**
     * Occupancy of one employee over a run of local dates. Reads with the
     * three queries of Agenda::for().
     *
     * @param  CarbonImmutable  $from  first local date; only its calendar date is used
     * @param  int  $days  number of days, at least 1
     */
    public static function forEmployee(Employee $employee, CarbonImmutable $from, int $days): self
    {
        return self::ofDays(Agenda::for($employee, $from, $days));
    }

    /**
     * @param  list<AgendaDay>  $days
     */
    public static function ofDays(array $days): self
    {
        $working = 0;
        $booked = 0;

        foreach ($days as $day) {
            $bookings = array_map(
                fn (AgendaBooking $b): TimeRange => new TimeRange($b->start, $b->end),
                $day->bookings,
            );

            $working += self::length($day->schedule->ranges);
            $booked += self::coveredMinutes($day->schedule->ranges, $bookings);
        }

        return new self($working, $booked);
    }

    /**
     * Minutes of `$ranges` covered by at least one of `$cover`.
     *
     * @param  list<TimeRange>  $ranges  sorted, non-overlapping
     * @param  list<TimeRange>  $cover  in any order, may overlap
     */
    public static function coveredMinutes(array $ranges, array $cover): int
    {
        usort($cover, fn (TimeRange $a, TimeRange $b): int => [$a->start, $a->end] <=> [$b->start, $b->end]);

        // Merge the cover so overlapping bookings count once.
        $merged = [];
        foreach ($cover as $range) {
            $last = array_key_last($merged);
            if ($last !== null && $range->start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range->end);
            } else {
                $merged[] = [$range->start, $range->end];
            }
        }

        $minutes = 0;
        foreach ($ranges as $range) {
            foreach ($merged as [$start, $end]) {
                $minutes += max(0, min($range->end, $end) - max($range->start, $start));
            }
        }

        return $minutes;
    }

    public function plus(self $other): self
    {
        return new self($this->workingMinutes + $other->workingMinutes, $this->bookedMinutes + $other->bookedMinutes);
    }

    /**
     * Booked share of the working time, 0-1; null without working time.
     */
    public function rate(): ?float
    {
        return $this->workingMinutes === 0 ? null : $this->bookedMinutes / $this->workingMinutes;
    }

    /**
     * @param  list<TimeRange>  $ranges
     */
    private static function length(array $ranges): int
    {
        return array_sum(array_map(fn (TimeRange $r): int => $r->end - $r->start, $ranges));
    }
}
