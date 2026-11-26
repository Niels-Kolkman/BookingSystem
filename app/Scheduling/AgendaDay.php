<?php

namespace App\Scheduling;

use App\Models\ScheduleException;
use Carbon\CarbonImmutable;

/**
 * One day in the agenda of an employee (ISSUE-21): the working hours, breaks
 * and public holiday from DaySchedule, the time off entered for the day and
 * the bookings that fall on it.
 */
final readonly class AgendaDay
{
    /**
     * @param  list<ScheduleException>  $timeOff  `unavailable` exceptions on this date, in time order
     * @param  list<AgendaBooking>  $bookings  bookings on this day, in real time order
     */
    public function __construct(
        public DaySchedule $schedule,
        public array $timeOff,
        public array $bookings,
    ) {}

    public function date(): CarbonImmutable
    {
        return $this->schedule->date;
    }

    public function holiday(): ?Holiday
    {
        return $this->schedule->holiday;
    }

    /**
     * Whole-day time off, e.g. a day of leave; the first when there are more.
     */
    public function wholeDayOff(): ?ScheduleException
    {
        foreach ($this->timeOff as $exception) {
            if ($exception->isWholeDay()) {
                return $exception;
            }
        }

        return null;
    }

    /**
     * Time off for part of the day, drawn as blocks in the grid.
     *
     * @return list<ScheduleException>
     */
    public function partialTimeOff(): array
    {
        return array_values(array_filter($this->timeOff, fn (ScheduleException $e): bool => ! $e->isWholeDay()));
    }

    public function isToday(): bool
    {
        return $this->date()->format('Y-m-d') === LocalTime::toLocal(CarbonImmutable::now())->format('Y-m-d');
    }

    /**
     * Everything drawn in the grid as [start, end) in minutes, to size the
     * visible hours. Whole-day time off is not drawn as a block.
     *
     * @return list<array{int, int}>
     */
    public function drawnRanges(): array
    {
        return [
            ...array_map(fn (TimeRange $r): array => [$r->start, $r->end], $this->schedule->ranges),
            ...array_map(fn (TimeRange $r): array => [$r->start, $r->end], $this->schedule->breaks),
            ...array_map(fn (ScheduleException $e): array => [$e->range()->start, $e->range()->end], $this->partialTimeOff()),
            ...array_map(fn (AgendaBooking $b): array => [$b->start, $b->end], $this->bookings),
        ];
    }
}
