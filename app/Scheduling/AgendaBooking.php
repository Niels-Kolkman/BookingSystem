<?php

namespace App\Scheduling;

use App\Models\Booking;
use Carbon\CarbonImmutable;

/**
 * A booking as it is drawn on one day of the agenda (ISSUE-21): its place in
 * wall-clock minutes since local midnight, [start, end), clipped to the day.
 *
 * The agenda grid is laid out in wall-clock time, like the schedule. On the
 * night the clock goes back the wall-clock end can lie before the start
 * (02:30 summer time + 30 min = 02:00 winter time); the block then gets its
 * real length instead. The labels always show the real times, with the
 * "(zomertijd)"/"(wintertijd)" suffix from LocalTime.
 */
final readonly class AgendaBooking
{
    public function __construct(
        public Booking $booking,
        public int $start,
        public int $end,
    ) {}

    /**
     * @param  CarbonImmutable  $day  local date (midnight, Europe/Amsterdam)
     */
    public static function onDay(Booking $booking, CarbonImmutable $day): self
    {
        $dayStart = LocalTime::midnight($day)->utc();
        $dayEnd = LocalTime::at($day, TimeRange::MINUTES_PER_DAY);

        $start = $booking->starts_at <= $dayStart ? 0 : self::wallMinutes($booking->starts_at);
        $end = $booking->ends_at >= $dayEnd ? TimeRange::MINUTES_PER_DAY : self::wallMinutes($booking->ends_at);

        if ($end <= $start) {
            $from = $booking->starts_at->max($dayStart);
            $until = $booking->ends_at->min($dayEnd);
            $real = max(1, (int) $from->diffInMinutes($until));
            $end = min(TimeRange::MINUTES_PER_DAY, $start + $real);
        }

        return new self($booking, $start, $end);
    }

    /**
     * "09:00 - 09:30", or with the suffix in the repeated hour.
     */
    public function timeLabel(): string
    {
        return LocalTime::time($this->booking->starts_at).' - '.LocalTime::time($this->booking->ends_at);
    }

    private static function wallMinutes(CarbonImmutable $moment): int
    {
        $local = LocalTime::toLocal($moment);

        return (int) $local->format('G') * 60 + (int) $local->format('i');
    }
}
