<?php

use App\Scheduling\DaySchedule;
use App\Scheduling\TimeRange;

/*
 * ISSUE-10: edge cases of combining the weekly schedule, closed holidays,
 * extra hours and time off. Pure logic, no database.
 */

/**
 * @param  list<string>  $weekly  "HH:MM-HH:MM"
 * @param  list<string>  $extra
 * @param  list<string>  $unavailable
 * @return list<string>
 */
function resolveLabels(array $weekly, bool $closed, array $extra, array $unavailable): array
{
    $toRanges = fn (array $ranges): array => array_map(
        fn (string $range): TimeRange => TimeRange::fromTimes(...explode('-', $range)),
        $ranges,
    );

    return array_map(
        fn (TimeRange $range): string => $range->label(),
        DaySchedule::resolve($toRanges($weekly), $closed, $toRanges($extra), $toRanges($unavailable)),
    );
}

it('returns nothing when there is nothing to work', function () {
    expect(resolveLabels([], false, [], []))->toBe([])
        ->and(resolveLabels([], true, [], ['09:00-10:00']))->toBe([]);
});

it('sorts unsorted weekly blocks', function () {
    expect(resolveLabels(['13:30-17:00', '09:00-12:30'], false, [], []))
        ->toBe(['09:00 - 12:30', '13:30 - 17:00']);
});

it('keeps the schedule unchanged when extra hours fall inside it', function () {
    expect(resolveLabels(['09:00-17:00'], false, ['10:00-11:00', '09:00-17:00'], []))
        ->toBe(['09:00 - 17:00']);
});

it('handles overlapping and duplicate time off', function () {
    expect(resolveLabels(['09:00-17:00'], false, [], ['10:00-12:00', '11:00-13:00', '10:00-12:00']))
        ->toBe(['09:00 - 10:00', '13:00 - 17:00']);
});

it('removes a block exactly covered by time off', function () {
    expect(resolveLabels(['09:00-12:00'], false, [], ['09:00-12:00']))->toBe([])
        ->and(resolveLabels(['09:00-12:00', '13:00-17:00'], false, [], ['12:00-13:00', '13:00-17:00']))
        ->toBe(['09:00 - 12:00']);
});

it('lets partial time off cut into extra hours on a closed holiday', function () {
    expect(resolveLabels(['09:00-17:00'], true, ['10:00-14:00'], ['13:00-15:00']))
        ->toBe(['10:00 - 13:00']);
});

it('merges ranges that reach the end of the day', function () {
    $ranges = DaySchedule::resolve([TimeRange::fromTimes('20:00', '23:00')], false, [new TimeRange(1380, 1440)], []);

    expect(array_map(fn (TimeRange $r): string => $r->label(), $ranges))->toBe(['20:00 - 24:00']);
});

it('treats touching ranges as not overlapping', function () {
    $morning = TimeRange::fromTimes('09:00', '12:00');

    expect($morning->overlaps(TimeRange::fromTimes('12:00', '13:00')))->toBeFalse()
        ->and($morning->overlaps(TimeRange::fromTimes('08:00', '09:00')))->toBeFalse()
        ->and($morning->overlaps(TimeRange::fromTimes('11:59', '12:00')))->toBeTrue()
        ->and($morning->overlaps(TimeRange::wholeDay()))->toBeTrue();
});

it('parses times without a leading zero', function () {
    $range = TimeRange::fromTimes('9:05', '17:30');

    expect([$range->start, $range->end])->toBe([545, 1050])
        ->and($range->label())->toBe('09:05 - 17:30');
});
