<?php

use App\Scheduling\Occupancy;
use App\Scheduling\TimeRange;

/*
 * ISSUE-22: the pure part of the occupancy: which minutes of the working
 * time are covered by bookings, and the rate.
 */

function occRange(int $start, int $end): TimeRange
{
    return new TimeRange($start, $end);
}

it('counts only booked time inside the working ranges', function () {
    $working = [occRange(540, 750), occRange(810, 1020)]; // 09:00-12:30, 13:30-17:00

    expect(Occupancy::coveredMinutes($working, [occRange(600, 660)]))->toBe(60)
        // 12:00-14:00 spans the break: 30 + 30 minutes.
        ->and(Occupancy::coveredMinutes($working, [occRange(720, 840)]))->toBe(60)
        // Before opening and after closing.
        ->and(Occupancy::coveredMinutes($working, [occRange(480, 540), occRange(1020, 1080)]))->toBe(0)
        ->and(Occupancy::coveredMinutes($working, [occRange(510, 570)]))->toBe(30);
});

it('counts overlapping bookings once, in any order', function () {
    $working = [occRange(540, 1020)];

    expect(Occupancy::coveredMinutes($working, [occRange(630, 690), occRange(600, 660), occRange(660, 720)]))->toBe(120)
        ->and(Occupancy::coveredMinutes($working, [occRange(600, 900), occRange(660, 700)]))->toBe(300);
});

it('covers nothing without working time or bookings', function () {
    expect(Occupancy::coveredMinutes([], [occRange(600, 660)]))->toBe(0)
        ->and(Occupancy::coveredMinutes([occRange(540, 1020)], []))->toBe(0);
});

it('has no rate without working time and adds up', function () {
    expect(Occupancy::none()->rate())->toBeNull()
        ->and((new Occupancy(480, 120))->rate())->toBe(0.25)
        ->and((new Occupancy(480, 120))->plus(new Occupancy(120, 30)))
        ->toEqual(new Occupancy(600, 150));
});
