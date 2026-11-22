<?php

use App\Scheduling\LocalTime;
use App\Scheduling\Slot;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/*
 * ISSUE-14 edge cases for LocalTime, beyond LocalTimeTest:
 * - round trips for every minute of both 2026 switch days;
 * - local midnight and the end of the day on and around the switch days;
 * - input that is not a CarbonImmutable, and input that must not change.
 */

function ltWall(int $minutes): string
{
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

describe('round trips over a whole switch day', function () {
    it('shows every existing wall-clock time of the March day as itself', function () {
        $date = CarbonImmutable::parse('2026-03-29');

        for ($m = 0; $m < 1440; $m++) {
            if ($m >= 120 && $m < 180) {
                continue; // 02:00-02:59 does not exist that night.
            }

            expect(LocalTime::time(LocalTime::at($date, $m)))->toBe(ltWall($m));
        }
    });

    it('shows every wall-clock time of the October day as itself, the repeated hour in summer time', function () {
        $date = CarbonImmutable::parse('2026-10-25');

        for ($m = 0; $m < 1440; $m++) {
            $suffix = ($m >= 120 && $m < 180) ? ' (zomertijd)' : '';

            expect(LocalTime::time(LocalTime::at($date, $m)))->toBe(ltWall($m).$suffix);
        }
    });

    it('marks exactly 120 minutes of the October day as ambiguous and none of the March day', function () {
        $count = function (string $date): int {
            $from = LocalTime::at(CarbonImmutable::parse($date), 0);
            $until = LocalTime::at(CarbonImmutable::parse($date), 1440);
            $ambiguous = 0;

            for ($moment = $from; $moment->lessThan($until); $moment = $moment->addMinute()) {
                $ambiguous += LocalTime::isAmbiguous($moment) ? 1 : 0;
            }

            return $ambiguous;
        };

        expect($count('2026-10-25'))->toBe(120)
            ->and($count('2026-03-29'))->toBe(0)
            ->and($count('2026-12-01'))->toBe(0);
    });

    it('keeps the at() result strictly increasing over the March day, with the gap collapsed', function () {
        $date = CarbonImmutable::parse('2026-03-29');
        $previous = null;

        for ($m = 0; $m <= 1440; $m++) {
            $moment = LocalTime::at($date, $m);

            if ($previous !== null) {
                // 02:00-02:59 and 03:00 itself all map to the jump (01:00
                // UTC), so from 02:01 up to 03:00 each equals the previous.
                $inGap = $m > 120 && $m <= 180;
                $inGap
                    ? expect($moment->equalTo($previous))->toBeTrue()
                    : expect($moment->greaterThan($previous))->toBeTrue();
            }

            $previous = $moment;
        }
    });
});

describe('day boundaries around the switch nights', function () {
    it('gives local midnight with the offset of that night', function (string $date, string $iso) {
        expect(LocalTime::midnight(CarbonImmutable::parse($date))->toIso8601String())->toBe($iso);
    })->with([
        'before the March jump' => ['2026-03-29', '2026-03-29T00:00:00+01:00'],
        'day after March jump' => ['2026-03-30', '2026-03-30T00:00:00+02:00'],
        'before the October change' => ['2026-10-25', '2026-10-25T00:00:00+02:00'],
        'day after October change' => ['2026-10-26', '2026-10-26T00:00:00+01:00'],
    ]);

    it('ends the day before a switch day at the switch day\'s local midnight', function () {
        expect(LocalTime::at(CarbonImmutable::parse('2026-03-28'), 1440)->format('Y-m-d H:i e'))->toBe('2026-03-28 23:00 UTC')
            ->and(LocalTime::at(CarbonImmutable::parse('2026-03-29'), 1440)->format('Y-m-d H:i e'))->toBe('2026-03-29 22:00 UTC')
            ->and(LocalTime::at(CarbonImmutable::parse('2026-10-25'), 1440)->format('Y-m-d H:i e'))->toBe('2026-10-25 23:00 UTC');
    });

    it('shows a late UTC evening as the next local day', function () {
        expect(LocalTime::dateTime(CarbonImmutable::parse('2026-07-01 22:30', 'UTC')))->toBe('02-07-2026 00:30')
            ->and(LocalTime::dateTime(CarbonImmutable::parse('2026-12-31 23:15', 'UTC')))->toBe('01-01-2027 00:15');
    });

    it('handles the 2025 switch nights too', function () {
        // 2025: forward on 30 March, back on 26 October.
        expect(LocalTime::at(CarbonImmutable::parse('2025-03-30'), 150)->format('Y-m-d H:i e'))->toBe('2025-03-30 01:00 UTC')
            ->and(LocalTime::time(CarbonImmutable::parse('2025-10-26 00:30', 'UTC')))->toBe('02:30 (zomertijd)')
            ->and(LocalTime::time(CarbonImmutable::parse('2025-10-26 01:30', 'UTC')))->toBe('02:30 (wintertijd)');
    });
});

describe('input types', function () {
    it('accepts a plain DateTimeImmutable and a mutable Carbon', function () {
        $plain = new DateTimeImmutable('2026-10-25 01:30', new DateTimeZone('UTC'));
        $mutable = Carbon::parse('2026-10-25 00:30', 'UTC');

        expect(LocalTime::time($plain))->toBe('02:30 (wintertijd)')
            ->and(LocalTime::dateTime($mutable))->toBe('25-10-2026 02:30 (zomertijd)')
            ->and(LocalTime::at($plain, 9 * 60)->format('Y-m-d H:i e'))->toBe('2026-10-25 08:00 UTC');
    });

    it('does not change the time zone of a mutable Carbon it is given', function () {
        $mutable = Carbon::parse('2026-10-25 00:30', 'UTC');

        LocalTime::toLocal($mutable);
        LocalTime::time($mutable);
        LocalTime::isAmbiguous($mutable);

        expect($mutable->format('Y-m-d H:i e'))->toBe('2026-10-25 00:30 UTC');
    });

    it('picks the first 02:30 in October, unlike parsing the bare string', function () {
        // PHP parses "2026-10-25 02:30" in Amsterdam as the second
        // occurrence (winter time). at() must not be replaced by a parse.
        $parsed = CarbonImmutable::parse('2026-10-25 02:30', 'Europe/Amsterdam');

        expect($parsed->utc()->format('H:i'))->toBe('01:30')
            ->and(LocalTime::at(CarbonImmutable::parse('2026-10-25'), 150)->format('H:i'))->toBe('00:30');
    });

    it('always returns at() in UTC', function () {
        expect(LocalTime::at(CarbonImmutable::parse('2026-07-01', 'Europe/Amsterdam'), 600)->getTimezone()->getName())->toBe('UTC')
            ->and(LocalTime::at(CarbonImmutable::parse('2026-03-29'), 150)->getTimezone()->getName())->toBe('UTC');
    });
});

describe('Slot', function () {
    it('gives its start in Amsterdam without changing the stored UTC moment', function () {
        $start = CarbonImmutable::parse('2026-10-25 01:30', 'UTC');
        $slot = new Slot($start, $start->addMinutes(30), $start->addMinutes(30));

        expect($slot->localStart()->format('H:i P'))->toBe('02:30 +01:00')
            ->and($slot->localStart()->getTimezone()->getName())->toBe('Europe/Amsterdam')
            ->and($slot->startsAt->format('H:i e'))->toBe('01:30 UTC')
            ->and($slot->label())->toBe('02:30 (wintertijd)');
    });
});
