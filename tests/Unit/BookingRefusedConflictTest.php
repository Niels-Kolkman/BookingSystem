<?php

use App\Bookings\BookingRefused;

/*
 * ISSUE-15: the booking page answers 409 (and reloads the free times) only
 * when the time was taken by another booking; anything else is 422.
 */

it('counts only a time taken by another booking as a conflict', function (BookingRefused $refused, bool $conflict, string $field) {
    expect($refused->isConflict())->toBe($conflict)
        ->and($refused->field)->toBe($field);
})->with([
    'employee busy' => [fn () => BookingRefused::employeeBusy(), true, 'starts_at'],
    'customer busy' => [fn () => BookingRefused::customerBusy(), true, 'starts_at'],
    'not offered' => [fn () => BookingRefused::notOffered(), false, 'starts_at'],
    'not bookable' => [fn () => BookingRefused::notBookable(), false, 'employee_id'],
]);
