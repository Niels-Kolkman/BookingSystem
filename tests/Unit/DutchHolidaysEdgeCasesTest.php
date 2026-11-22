<?php

use App\Scheduling\DutchHolidays;
use App\Scheduling\Holiday;
use Carbon\CarbonImmutable;

/*
 * ISSUE-10: edge cases of the calculated Dutch public holidays.
 */

/**
 * @return array<string, CarbonImmutable> key => date
 */
function holidaysByKey(int $year): array
{
    $byKey = [];
    foreach (DutchHolidays::forYear($year) as $holiday) {
        $byKey[$holiday->key] = $holiday->date;
    }

    return $byKey;
}

it('handles the exceptional years of the Easter computus', function (int $year, string $expected) {
    expect(DutchHolidays::easterSunday($year)->format('Y-m-d'))->toBe($expected);
})->with([
    [1818, '1818-03-22'], // earliest possible date
    [1943, '1943-04-25'], // latest possible date
    [1954, '1954-04-18'], // correction cases of the algorithm
    [1981, '1981-04-19'],
    [2000, '2000-04-23'], // century leap year
    [2049, '2049-04-18'],
    [2076, '2076-04-19'],
    [2100, '2100-03-28'], // century, not a leap year
    [2285, '2285-03-22'],
]);

it('keeps every holiday on its expected weekday and in order, every year', function () {
    $weekdays = [
        'goede_vrijdag' => 'Friday',
        'eerste_paasdag' => 'Sunday',
        'tweede_paasdag' => 'Monday',
        'hemelvaartsdag' => 'Thursday',
        'eerste_pinksterdag' => 'Sunday',
        'tweede_pinksterdag' => 'Monday',
    ];

    for ($year = 2000; $year <= 2100; $year++) {
        $holidays = DutchHolidays::forYear($year);
        $byKey = holidaysByKey($year);
        $dates = array_map(fn (Holiday $h): string => $h->date->format('Y-m-d'), $holidays);
        $sorted = $dates;
        sort($sorted);

        expect($holidays)->toHaveCount(11)
            ->and($byKey)->toHaveCount(11)
            ->and($dates)->toBe($sorted)
            // Two holidays can share a date, see the test below.
            ->and(count(array_unique($dates)))->toBeGreaterThanOrEqual(10);

        foreach ($weekdays as $key => $weekday) {
            expect($byKey[$key]->format('l'))->toBe($weekday, "{$key} {$year}");
        }

        foreach ($holidays as $holiday) {
            expect((int) $holiday->date->format('Y'))->toBe($year);
        }

        expect($byKey['koningsdag']->isSunday())->toBeFalse("koningsdag {$year}")
            ->and($byKey['koningsdag']->format('m-d'))->toBeIn(['04-26', '04-27']);
    }
});

it('lists both holidays when Hemelvaartsdag falls on Bevrijdingsdag', function () {
    // Easter on 27 March puts Hemelvaartsdag on 5 May: 2005 and 2016, the
    // only such years between 2000 and 2100.
    foreach ([2005, 2016] as $year) {
        $onMayFifth = array_values(array_map(
            fn (Holiday $h): string => $h->key,
            array_filter(DutchHolidays::forYear($year), fn (Holiday $h): bool => $h->date->format('m-d') === '05-05'),
        ));

        expect($onMayFifth)->toBe(['bevrijdingsdag', 'hemelvaartsdag']);
    }

    for ($year = 2017; $year <= 2100; $year++) {
        expect(holidaysByKey($year)['hemelvaartsdag']->format('m-d'))->not->toBe('05-05', (string) $year);
    }
});

it('keeps Koningsdag on 27 April when it is a Saturday', function () {
    // 27 April 2030 is a Saturday: only Sundays move.
    expect(holidaysByKey(2030)['koningsdag']->format('Y-m-d'))->toBe('2030-04-27');
});

it('returns no holiday on ordinary dates such as a leap day or New Year\'s Eve', function () {
    expect(DutchHolidays::on(CarbonImmutable::parse('2028-02-29')))->toBeNull()
        ->and(DutchHolidays::on(CarbonImmutable::parse('2026-12-31')))->toBeNull();
});

it('returns nothing for a range without holidays or an inverted range', function () {
    expect(DutchHolidays::between(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-11-30')))->toBe([])
        ->and(DutchHolidays::between(CarbonImmutable::parse('2026-12-26'), CarbonImmutable::parse('2026-12-25')))->toBe([]);
});

it('compares only calendar dates for a single-day range', function () {
    $keys = array_map(
        fn (Holiday $h): string => $h->key,
        DutchHolidays::between(CarbonImmutable::parse('2026-04-27 18:00'), CarbonImmutable::parse('2026-04-27 08:00')),
    );

    expect($keys)->toBe(['koningsdag']);
});

it('lists every holiday of a multi-year range exactly once', function () {
    expect(DutchHolidays::between(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2028-12-31')))->toHaveCount(33);
});
