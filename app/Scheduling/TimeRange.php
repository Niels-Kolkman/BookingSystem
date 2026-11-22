<?php

namespace App\Scheduling;

use App\Models\WorkingHour;
use InvalidArgumentException;

/**
 * A range of local wall-clock time within one day, in minutes since
 * midnight: [start, end). 0-1440 is the whole day.
 */
final readonly class TimeRange
{
    public const MINUTES_PER_DAY = 1440;

    public function __construct(
        public int $start,
        public int $end,
    ) {
        if ($start < 0 || $end > self::MINUTES_PER_DAY || $end <= $start) {
            throw new InvalidArgumentException("Invalid time range {$start}-{$end}.");
        }
    }

    /**
     * "09:00", "12:30" -> 540-750.
     */
    public static function fromTimes(string $startsAt, string $endsAt): self
    {
        return new self(WorkingHour::toMinutes($startsAt), WorkingHour::toMinutes($endsAt));
    }

    public static function wholeDay(): self
    {
        return new self(0, self::MINUTES_PER_DAY);
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $this->end > $other->start;
    }

    /**
     * "09:00 - 12:30"; the end of the day reads as "24:00".
     */
    public function label(): string
    {
        return self::format($this->start).' - '.self::format($this->end);
    }

    private static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
