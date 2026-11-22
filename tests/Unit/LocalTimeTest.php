<?php

use App\Scheduling\LocalTime;
use Carbon\CarbonImmutable;

/*
 * ISSUE-14: wall-clock time in Europe/Amsterdam <-> UTC moments, around the
 * switch nights of 2026:
 * - Sunday 2026-03-29: at 02:00 (01:00 UTC) the clock jumps to 03:00;
 *   02:00-02:59 does not exist.
 * - Sunday 2026-10-25: at 03:00 (01:00 UTC) the clock goes back to 02:00;
 *   02:00-02:59 happens twice, at 00:00-00:59 UTC and 01:00-01:59 UTC.
 */

function utcOf(string $date, string $time): string
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return LocalTime::at(CarbonImmutable::parse($date, 'UTC'), $hours * 60 + $minutes)->format('Y-m-d H:i e');
}

describe('wall-clock time to UTC', function () {
    it('converts a normal winter and summer day', function () {
        expect(utcOf('2026-12-01', '09:00'))->toBe('2026-12-01 08:00 UTC')
            ->and(utcOf('2026-07-01', '09:00'))->toBe('2026-07-01 07:00 UTC');
    });

    it('converts the times around the jump forward in March', function () {
        expect(utcOf('2026-03-29', '00:00'))->toBe('2026-03-28 23:00 UTC')
            ->and(utcOf('2026-03-29', '01:59'))->toBe('2026-03-29 00:59 UTC')
            ->and(utcOf('2026-03-29', '03:00'))->toBe('2026-03-29 01:00 UTC')
            ->and(utcOf('2026-03-29', '04:00'))->toBe('2026-03-29 02:00 UTC');
    });

    it('maps every time the clock skips in March to the moment of the jump', function (string $time) {
        expect(utcOf('2026-03-29', $time))->toBe('2026-03-29 01:00 UTC');
    })->with(['02:00', '02:01', '02:30', '02:59']);

    it('maps a time in the repeated hour in October to its first occurrence, in summer time', function (string $time, string $utc) {
        expect(utcOf('2026-10-25', $time))->toBe($utc);
    })->with([
        ['01:59', '2026-10-24 23:59 UTC'],
        ['02:00', '2026-10-25 00:00 UTC'],
        ['02:30', '2026-10-25 00:30 UTC'],
        ['02:59', '2026-10-25 00:59 UTC'],
        ['03:00', '2026-10-25 02:00 UTC'],
    ]);

    it('has 23 hours on the March switch day and 25 on the October one', function () {
        $hours = fn (string $date): float => LocalTime::at(CarbonImmutable::parse($date), 0)
            ->diffInHours(LocalTime::at(CarbonImmutable::parse($date), 1440));

        expect($hours('2026-03-29'))->toEqual(23)
            ->and($hours('2026-10-25'))->toEqual(25)
            ->and($hours('2026-12-01'))->toEqual(24);
    });

    it('only uses the calendar date of the given date, whatever its zone', function () {
        // 23:30 UTC on 28 March is already the 29th in Amsterdam, but the
        // calendar date as written (28th) is what counts.
        $date = CarbonImmutable::parse('2026-03-28 23:30', 'UTC');

        expect(LocalTime::at($date, 9 * 60)->format('Y-m-d H:i'))->toBe('2026-03-28 08:00');
    });

    it('finds the jump in other years too', function () {
        // 2027: forward on 28 March, back on 31 October.
        expect(utcOf('2027-03-28', '02:30'))->toBe('2027-03-28 01:00 UTC')
            ->and(utcOf('2027-10-31', '02:30'))->toBe('2027-10-31 00:30 UTC');
    });

    it('rejects minutes outside the day', function (int $minutes) {
        LocalTime::at(CarbonImmutable::parse('2026-12-01'), $minutes);
    })->with(['before midnight' => [-1], 'after the next midnight' => [1441]])->throws(UnexpectedValueException::class);
});

describe('display', function () {
    it('shows times in Amsterdam without a suffix on normal moments', function () {
        expect(LocalTime::time(CarbonImmutable::parse('2026-12-01 08:15', 'UTC')))->toBe('09:15')
            ->and(LocalTime::dateTime(CarbonImmutable::parse('2026-07-01 07:15', 'UTC')))->toBe('01-07-2026 09:15')
            ->and(LocalTime::time(CarbonImmutable::parse('2026-03-29 01:00', 'UTC')))->toBe('03:00');
    });

    it('tells the two 02:30s in October apart', function () {
        $first = CarbonImmutable::parse('2026-10-25 00:30', 'UTC');
        $second = CarbonImmutable::parse('2026-10-25 01:30', 'UTC');

        expect(LocalTime::isAmbiguous($first))->toBeTrue()
            ->and(LocalTime::isAmbiguous($second))->toBeTrue()
            ->and(LocalTime::time($first))->toBe('02:30 (zomertijd)')
            ->and(LocalTime::time($second))->toBe('02:30 (wintertijd)')
            ->and(LocalTime::dateTime($second))->toBe('25-10-2026 02:30 (wintertijd)');
    });

    it('only adds the suffix inside the repeated hour', function (string $utc, string $label) {
        expect(LocalTime::time(CarbonImmutable::parse($utc, 'UTC')))->toBe($label);
    })->with([
        ['2026-10-24 23:59', '01:59'],
        ['2026-10-25 00:00', '02:00 (zomertijd)'],
        ['2026-10-25 00:59', '02:59 (zomertijd)'],
        ['2026-10-25 01:00', '02:00 (wintertijd)'],
        ['2026-10-25 01:59', '02:59 (wintertijd)'],
        ['2026-10-25 02:00', '03:00'],
    ]);

    it('gives the same result for a moment in any zone', function () {
        $local = CarbonImmutable::parse('2026-10-25T02:30:00+01:00');

        expect(LocalTime::time($local))->toBe('02:30 (wintertijd)')
            ->and(LocalTime::toLocal($local)->utc()->format('H:i'))->toBe('01:30');
    });
});
