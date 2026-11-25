<?php

use App\Scheduling\Slot;
use Carbon\CarbonImmutable;

/*
 * ISSUE-15: the booking page sends a slot back as local time with its
 * offset, so the two 02:30s of the night the clock goes back stay apart.
 */

function inputValueAt(string $utc): string
{
    $start = CarbonImmutable::parse($utc, 'UTC');

    return (new Slot($start, $start->addMinutes(30), $start->addMinutes(30)))->inputValue();
}

it('writes the start as local time with a numeric offset', function (string $utc, string $expected) {
    expect(inputValueAt($utc))->toBe($expected);
})->with([
    'winter time' => ['2026-12-01 08:15', '2026-12-01T09:15:00+01:00'],
    'summer time' => ['2027-03-30 07:00', '2027-03-30T09:00:00+02:00'],
    'first 02:30 when the clock goes back' => ['2026-10-25 00:30', '2026-10-25T02:30:00+02:00'],
    'second 02:30 when the clock goes back' => ['2026-10-25 01:30', '2026-10-25T02:30:00+01:00'],
]);
