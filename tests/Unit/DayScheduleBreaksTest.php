<?php

use App\Scheduling\DaySchedule;
use App\Scheduling\TimeRange;

/*
 * ISSUE-21: the breaks drawn in the agenda are the gaps between the planned
 * working blocks, without time off. Pure logic, no database.
 */

/**
 * @param  list<string>  $ranges  "HH:MM-HH:MM"
 * @return list<TimeRange>
 */
function breakRanges(array $ranges): array
{
    return array_map(fn (string $range): TimeRange => TimeRange::fromTimes(...explode('-', $range)), $ranges);
}

/**
 * @param  list<string>  $weekly
 * @param  list<string>  $extra
 * @param  list<string>  $unavailable
 * @return list<string>
 */
function breakLabels(array $weekly, bool $closed = false, array $extra = [], array $unavailable = []): array
{
    return array_map(
        fn (TimeRange $range): string => $range->label(),
        DaySchedule::breaks(breakRanges($weekly), $closed, breakRanges($extra), breakRanges($unavailable)),
    );
}

it('finds the break of a split shift', function () {
    expect(breakLabels(['09:00-12:30', '13:30-17:00']))->toBe(['12:30 - 13:30']);
});

it('finds one break per gap, in time order, whatever the input order', function () {
    expect(breakLabels(['14:00-17:00', '08:00-10:00', '10:30-13:00']))->toBe(['10:00 - 10:30', '13:00 - 14:00']);
});

it('has no break for one block, touching blocks or a day off', function (array $weekly) {
    expect(breakLabels($weekly))->toBe([]);
})->with([
    'one block' => [['09:00-17:00']],
    'touching blocks' => [['09:00-12:00', '12:00-17:00']],
    'overlapping blocks' => [['09:00-13:00', '12:00-17:00']],
    'day off' => [[]],
]);

it('does not count the time before the first or after the last block', function () {
    expect(breakLabels(['10:00-12:00', '13:00-15:00']))->toBe(['12:00 - 13:00']);
});

it('lets extra hours fill or split a break', function () {
    expect(breakLabels(['09:00-12:00', '14:00-17:00'], extra: ['12:00-14:00']))->toBe([])
        ->and(breakLabels(['09:00-12:00', '14:00-17:00'], extra: ['12:30-13:30']))->toBe(['12:00 - 12:30', '13:30 - 14:00']);
});

it('finds a break between extra hours and the weekly schedule', function () {
    expect(breakLabels(['13:00-17:00'], extra: ['08:00-11:00']))->toBe(['11:00 - 13:00']);
});

it('has no weekly breaks on a closed holiday, only those between extra hours', function () {
    expect(breakLabels(['09:00-12:00', '13:00-17:00'], closed: true))->toBe([])
        ->and(breakLabels(['09:00-12:00', '13:00-17:00'], closed: true, extra: ['10:00-11:00', '15:00-16:00']))
        ->toBe(['11:00 - 15:00']);
});

it('leaves out the part of a break that is time off anyway', function () {
    $weekly = ['09:00-12:00', '13:00-17:00'];

    expect(breakLabels($weekly, unavailable: ['12:30-15:00']))->toBe(['12:00 - 12:30'])
        ->and(breakLabels($weekly, unavailable: ['11:00-13:00']))->toBe([])
        ->and(breakLabels($weekly, unavailable: ['00:00-23:59']))->toBe([]);
});

it('keeps a break next to time off that makes the gap wider', function () {
    // Off 10:00-12:00: the break is still 12:00-13:00, the rest is time off.
    expect(breakLabels(['09:00-12:00', '13:00-17:00'], unavailable: ['10:00-12:00']))->toBe(['12:00 - 13:00']);
});
