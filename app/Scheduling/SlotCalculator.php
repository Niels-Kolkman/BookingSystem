<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The free start times on one local date (ISSUE-11). Pure logic: no
 * database, no clock, no HTTP. AvailableSlots feeds it from Eloquent.
 *
 * Rules:
 * - Start times step through every working range by `$intervalMinutes`,
 *   starting at the beginning of that range.
 * - The appointment itself (the service duration) must fit inside one
 *   working range.
 * - The buffer after the appointment may run past the end of the working
 *   range (tidying up after the last customer), but it may not overlap a
 *   busy period. So the full blocked time, start up to blocked_until, has
 *   to be free; this is the same overlap rule booking creation re-checks
 *   (docs/erd.md), so an offered slot is never rejected for overlap.
 * - Start times before `$notBefore` (usually "now") are left out.
 *
 * The local schedule is converted to UTC per range boundary, and start
 * times and durations step in real elapsed time (ISSUE-14, LocalTime):
 * - when the clock jumps forward (last Sunday of March) no start in
 *   02:00-02:59 is offered, and a range that starts or ends in that hour
 *   starts or ends at the jump, so 01:00-04:00 is 2 hours of slots;
 * - when the clock goes back (last Sunday of October) 02:00-02:59 is
 *   offered twice, once in summer and once in winter time, so 01:00-04:00
 *   is 4 hours of slots. Slot::label() tells the two apart.
 */
final class SlotCalculator
{
    /**
     * @param  CarbonImmutable  $date  local date; only its calendar date is used
     * @param  list<TimeRange>  $workingRanges  local working time on that date, see DaySchedule
     * @param  list<BusyPeriod>  $busy  UTC periods that are already taken
     * @return list<Slot> in time order
     */
    public static function calculate(
        CarbonImmutable $date,
        array $workingRanges,
        int $durationMinutes,
        int $bufferMinutes,
        array $busy,
        int $intervalMinutes,
        ?CarbonImmutable $notBefore = null,
    ): array {
        if ($durationMinutes < 1) {
            throw new InvalidArgumentException('The duration must be at least one minute.');
        }
        if ($bufferMinutes < 0) {
            throw new InvalidArgumentException('The buffer cannot be negative.');
        }
        if ($intervalMinutes < 1) {
            throw new InvalidArgumentException('The slot interval must be at least one minute.');
        }

        $slots = [];
        foreach ($workingRanges as $range) {
            $rangeEnd = self::toUtc($date, $range->end);

            for ($start = self::toUtc($date, $range->start); $start->addMinutes($durationMinutes) <= $rangeEnd; $start = $start->addMinutes($intervalMinutes)) {
                if ($notBefore !== null && $start < $notBefore) {
                    continue;
                }

                $endsAt = $start->addMinutes($durationMinutes);
                $blockedUntil = $endsAt->addMinutes($bufferMinutes);

                if (self::isTaken($busy, $start, $blockedUntil)) {
                    continue;
                }

                $slots[] = new Slot($start, $endsAt, $blockedUntil);
            }
        }

        usort($slots, fn (Slot $a, Slot $b): int => $a->startsAt <=> $b->startsAt);

        return $slots;
    }

    /**
     * Minutes since local midnight on `$date` as a UTC moment. 1440 is the
     * next local midnight. On the switch nights a time in the skipped hour
     * is the moment of the jump and a time in the repeated hour its first
     * occurrence (LocalTime::at()).
     */
    public static function toUtc(CarbonImmutable $date, int $minutes): CarbonImmutable
    {
        return LocalTime::at($date, $minutes);
    }

    /**
     * @param  list<BusyPeriod>  $busy
     */
    private static function isTaken(array $busy, CarbonImmutable $startsAt, CarbonImmutable $blockedUntil): bool
    {
        foreach ($busy as $period) {
            if ($period->overlaps($startsAt, $blockedUntil)) {
                return true;
            }
        }

        return false;
    }
}
