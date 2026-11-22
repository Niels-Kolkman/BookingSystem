<?php

use App\Scheduling\DutchHolidays;
use App\Scheduling\Holiday;
use Carbon\CarbonImmutable;

/*
 * ISSUE-10: Dutch public holidays are calculated, not stored.
 */

/**
 * @return array<string, string> key => Y-m-d
 */
function holidayDates(int $year): array
{
    $dates = [];
    foreach (DutchHolidays::forYear($year) as $holiday) {
        $dates[$holiday->key] = $holiday->date->format('Y-m-d');
    }

    return $dates;
}

it('calculates Easter Sunday', function (int $year, string $expected) {
    expect(DutchHolidays::easterSunday($year)->format('Y-m-d'))->toBe($expected);
})->with([
    [2008, '2008-03-23'], // early Easter
    [2019, '2019-04-21'],
    [2024, '2024-03-31'],
    [2025, '2025-04-20'],
    [2026, '2026-04-05'],
    [2027, '2027-03-28'],
    [2038, '2038-04-25'], // latest possible date
]);

it('lists all Dutch holidays of 2026 in date order', function () {
    expect(holidayDates(2026))->toBe([
        'nieuwjaarsdag' => '2026-01-01',
        'goede_vrijdag' => '2026-04-03',
        'eerste_paasdag' => '2026-04-05',
        'tweede_paasdag' => '2026-04-06',
        'koningsdag' => '2026-04-27',
        'bevrijdingsdag' => '2026-05-05',
        'hemelvaartsdag' => '2026-05-14',
        'eerste_pinksterdag' => '2026-05-24',
        'tweede_pinksterdag' => '2026-05-25',
        'eerste_kerstdag' => '2026-12-25',
        'tweede_kerstdag' => '2026-12-26',
    ]);
});

it('moves Koningsdag to 26 April when the 27th is a Sunday', function () {
    // 27 April 2025 and 2031 are Sundays.
    expect(holidayDates(2025)['koningsdag'])->toBe('2025-04-26')
        ->and(holidayDates(2031)['koningsdag'])->toBe('2031-04-26')
        ->and(holidayDates(2027)['koningsdag'])->toBe('2027-04-27');
});

it('keeps holidays in date order when Hemelvaartsdag comes before Bevrijdingsdag', function () {
    // 2008: Hemelvaartsdag on 1 May.
    $keys = array_keys(holidayDates(2008));

    expect(array_search('hemelvaartsdag', $keys))->toBeLessThan(array_search('bevrijdingsdag', $keys));
});

it('finds the holiday on a date, using only the calendar date', function () {
    expect(DutchHolidays::on(CarbonImmutable::parse('2026-12-25 23:30', 'Europe/Amsterdam'))?->name)->toBe('Eerste Kerstdag')
        ->and(DutchHolidays::on(CarbonImmutable::parse('2026-12-24')))->toBeNull()
        ->and(DutchHolidays::on(new DateTimeImmutable('2026-05-14')))->toBeInstanceOf(Holiday::class);
});

it('lists holidays in a range across a year boundary, bounds included', function () {
    $names = array_map(
        fn (Holiday $holiday): string => $holiday->date->format('Y-m-d').' '.$holiday->name,
        DutchHolidays::between(CarbonImmutable::parse('2026-12-25'), CarbonImmutable::parse('2027-01-01')),
    );

    expect($names)->toBe([
        '2026-12-25 Eerste Kerstdag',
        '2026-12-26 Tweede Kerstdag',
        '2027-01-01 Nieuwjaarsdag',
    ]);
});

it('uses midnight in Europe/Amsterdam for holiday dates', function () {
    $holiday = DutchHolidays::forYear(2026)[0];

    expect($holiday->date->getTimezone()->getName())->toBe('Europe/Amsterdam')
        ->and($holiday->date->format('H:i'))->toBe('00:00');
});
