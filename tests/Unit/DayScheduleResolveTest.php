<?php

use App\Scheduling\DaySchedule;
use App\Scheduling\TimeRange;

/*
 * ISSUE-10: how the weekly schedule, a closed holiday, extra hours and time
 * off combine for one date. Pure logic, no database.
 */

/**
 * @param  list<string>  $ranges  "HH:MM-HH:MM"
 * @return list<TimeRange>
 */
function toRanges(array $ranges): array
{
    return array_map(fn (string $range): TimeRange => TimeRange::fromTimes(...explode('-', $range)), $ranges);
}

/**
 * @param  list<TimeRange>  $ranges
 * @return list<string>
 */
function rangeLabels(array $ranges): array
{
    return array_map(fn (TimeRange $range): string => $range->label(), $ranges);
}

it('keeps the weekly schedule when nothing deviates', function () {
    expect(rangeLabels(DaySchedule::resolve(toRanges(['09:00-12:30', '13:30-17:00']), false, [], [])))
        ->toBe(['09:00 - 12:30', '13:30 - 17:00']);
});

it('clears the weekly schedule on a closed holiday', function () {
    expect(DaySchedule::resolve(toRanges(['09:00-17:00']), true, [], []))->toBe([]);
});

it('allows extra hours on a closed holiday', function () {
    expect(rangeLabels(DaySchedule::resolve(toRanges(['09:00-17:00']), true, toRanges(['10:00-14:00']), [])))
        ->toBe(['10:00 - 14:00']);
});

it('adds extra hours on a day off and merges them with the schedule', function () {
    expect(rangeLabels(DaySchedule::resolve([], false, toRanges(['10:00-12:00']), [])))->toBe(['10:00 - 12:00'])
        // Overlapping and touching ranges join into one.
        ->and(rangeLabels(DaySchedule::resolve(toRanges(['09:00-12:30', '13:30-17:00']), false, toRanges(['12:30-13:30', '16:00-19:00']), [])))
        ->toBe(['09:00 - 19:00']);
});

it('removes a whole day off', function () {
    expect(DaySchedule::resolve(toRanges(['09:00-17:00']), false, toRanges(['18:00-20:00']), [TimeRange::wholeDay()]))->toBe([]);
});

it('removes partial time off, splitting a block when needed', function () {
    expect(rangeLabels(DaySchedule::resolve(toRanges(['09:00-17:00']), false, [], toRanges(['11:00-13:00']))))
        ->toBe(['09:00 - 11:00', '13:00 - 17:00'])
        ->and(rangeLabels(DaySchedule::resolve(toRanges(['09:00-12:00', '13:00-17:00']), false, [], toRanges(['08:00-09:30', '16:00-18:00']))))
        ->toBe(['09:30 - 12:00', '13:00 - 16:00']);
});

it('lets time off win over extra hours', function () {
    expect(rangeLabels(DaySchedule::resolve([], false, toRanges(['09:00-17:00']), toRanges(['12:00-13:00']))))
        ->toBe(['09:00 - 12:00', '13:00 - 17:00']);
});

it('ignores time off outside working hours', function () {
    expect(rangeLabels(DaySchedule::resolve(toRanges(['09:00-17:00']), false, [], toRanges(['17:00-20:00', '07:00-09:00']))))
        ->toBe(['09:00 - 17:00']);
});

it('rejects empty or inverted time ranges', function (int $start, int $end) {
    new TimeRange($start, $end);
})->throws(InvalidArgumentException::class)->with([
    [600, 600],
    [600, 540],
    [-1, 60],
    [0, 1441],
]);

it('labels the end of the day as 24:00', function () {
    expect(TimeRange::wholeDay()->label())->toBe('00:00 - 24:00');
});
