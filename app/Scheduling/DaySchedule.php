<?php

namespace App\Scheduling;

use App\Enums\ScheduleExceptionType;
use App\Enums\Weekday;
use App\Models\Employee;
use App\Models\ScheduleException;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use UnexpectedValueException;

/**
 * The hours an employee actually works on one concrete local date
 * (ISSUE-10): the weekly schedule with public holidays and schedule
 * exceptions applied. The slot calculation (ISSUE-11) starts from
 * `$employee->scheduleOn($date)->ranges` and subtracts existing bookings.
 *
 * Precedence, from low to high:
 * 1. the weekly schedule for that weekday;
 * 2. a closed public holiday (config/holidays.php) clears the weekly hours;
 * 3. `extra_hours` exceptions are added, also on a holiday, so an admin can
 *    let someone work on Koningsdag;
 * 4. `unavailable` exceptions are removed last and always win, so a day off
 *    is never overruled by other entries.
 */
final readonly class DaySchedule
{
    /**
     * @param  CarbonImmutable  $date  local date (midnight, Europe/Amsterdam)
     * @param  list<TimeRange>  $ranges  working time, sorted, non-overlapping
     * @param  Holiday|null  $holiday  the public holiday on this date, closed or not
     * @param  list<TimeRange>  $breaks  breaks between the working blocks, see breaks()
     */
    public function __construct(
        public CarbonImmutable $date,
        public array $ranges,
        public ?Holiday $holiday,
        public array $breaks = [],
    ) {}

    /**
     * Only the calendar date of `$date` is used, so pass a date in
     * Europe/Amsterdam (convert UTC moments first). Uses the loaded
     * `workingHours` and, when loaded, `scheduleExceptions` relations.
     */
    public static function for(Employee $employee, DateTimeInterface $date): self
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), DutchHolidays::TIMEZONE)
            ?: throw new UnexpectedValueException('Invalid date.');

        $exceptions = $employee->relationLoaded('scheduleExceptions')
            ? $employee->scheduleExceptions->filter(fn (ScheduleException $e): bool => $e->date->isSameDay($day))
            : $employee->scheduleExceptions()->onDate($day)->get();

        $holiday = DutchHolidays::on($day);

        $rangesOf = fn (ScheduleExceptionType $type): array => $exceptions
            ->filter(fn (ScheduleException $e): bool => $e->type === $type)
            ->map(fn (ScheduleException $e): TimeRange => $e->range())
            ->values()
            ->all();

        $parts = [
            'weekly' => $employee->workingHoursOn(Weekday::fromDate($day))
                ->map(fn (WorkingHour $block): TimeRange => TimeRange::fromTimes($block->starts_at, $block->ends_at))
                ->values()
                ->all(),
            'closed' => $holiday?->isClosed() ?? false,
            'extra' => $rangesOf(ScheduleExceptionType::ExtraHours),
            'unavailable' => $rangesOf(ScheduleExceptionType::Unavailable),
        ];

        return new self($day, self::resolve(...$parts), $holiday, self::breaks(...$parts));
    }

    /**
     * The pure combination rule, see the class comment.
     *
     * @param  list<TimeRange>  $weekly
     * @param  list<TimeRange>  $extra
     * @param  list<TimeRange>  $unavailable
     * @return list<TimeRange> sorted, merged (touching ranges join)
     */
    public static function resolve(array $weekly, bool $closed, array $extra, array $unavailable): array
    {
        $ranges = self::merge([...($closed ? [] : $weekly), ...$extra]);

        foreach ($unavailable as $off) {
            $ranges = self::subtract($ranges, $off);
        }

        return $ranges;
    }

    /**
     * The breaks of a day (ISSUE-21): the gaps between the planned working
     * blocks (weekly schedule and extra hours, so a split shift has one),
     * without the parts that are time off anyway. Time before the first and
     * after the last block is not a break.
     *
     * @param  list<TimeRange>  $weekly
     * @param  list<TimeRange>  $extra
     * @param  list<TimeRange>  $unavailable
     * @return list<TimeRange> sorted, non-overlapping
     */
    public static function breaks(array $weekly, bool $closed, array $extra, array $unavailable): array
    {
        $planned = self::resolve($weekly, $closed, $extra, []);

        $gaps = [];
        for ($i = 1; $i < count($planned); $i++) {
            // Merged ranges never touch, so every gap has a length.
            $gaps[] = new TimeRange($planned[$i - 1]->end, $planned[$i]->start);
        }

        return self::resolve($gaps, false, [], $unavailable);
    }

    public function isAvailable(): bool
    {
        return $this->ranges !== [];
    }

    /**
     * "09:00 - 12:30, 13:30 - 17:00" or "Niet beschikbaar".
     */
    public function label(): string
    {
        if (! $this->isAvailable()) {
            return 'Niet beschikbaar';
        }

        return implode(', ', array_map(fn (TimeRange $range): string => $range->label(), $this->ranges));
    }

    /**
     * @param  list<TimeRange>  $ranges
     * @return list<TimeRange>
     */
    private static function merge(array $ranges): array
    {
        usort($ranges, fn (TimeRange $a, TimeRange $b): int => [$a->start, $a->end] <=> [$b->start, $b->end]);

        $merged = [];
        foreach ($ranges as $range) {
            $lastKey = array_key_last($merged);
            if ($lastKey !== null && $range->start <= $merged[$lastKey]->end) {
                $merged[$lastKey] = new TimeRange($merged[$lastKey]->start, max($merged[$lastKey]->end, $range->end));
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }

    /**
     * @param  list<TimeRange>  $ranges
     * @return list<TimeRange>
     */
    private static function subtract(array $ranges, TimeRange $off): array
    {
        $result = [];
        foreach ($ranges as $range) {
            if (! $range->overlaps($off)) {
                $result[] = $range;

                continue;
            }
            if ($range->start < $off->start) {
                $result[] = new TimeRange($range->start, $off->start);
            }
            if ($range->end > $off->end) {
                $result[] = new TimeRange($off->end, $range->end);
            }
        }

        return $result;
    }
}
