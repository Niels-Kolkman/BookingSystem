<?php

use App\Enums\BookingStatus;

/*
 * The state machine of BookingStatus (ISSUE-18). The 'status transitions'
 * dataset (every pair of statuses) is in tests/Datasets.
 */

it('allows only the defined transitions', function (BookingStatus $from, BookingStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed)
        ->and(in_array($to, $from->allowedTransitions(), true))->toBe($allowed);
})->with('status transitions');

it('ends in cancelled, completed and no-show', function (BookingStatus $status, bool $final) {
    expect($status->isFinal())->toBe($final);
})->with([
    'requested' => [BookingStatus::Requested, false],
    'confirmed' => [BookingStatus::Confirmed, false],
    'cancelled' => [BookingStatus::Cancelled, true],
    'completed' => [BookingStatus::Completed, true],
    'no_show' => [BookingStatus::NoShow, true],
]);

it('gives every status that blocks time a way out', function (BookingStatus $status) {
    // Otherwise the slot would stay taken forever.
    expect($status->blocksTime())->toBe(! $status->isFinal());
})->with('booking statuses');

it('never lists a status as a transition to itself, nor twice', function (BookingStatus $status) {
    $allowed = $status->allowedTransitions();

    expect($allowed)->not->toContain($status)
        ->and(array_unique(array_map(fn (BookingStatus $s): string => $s->value, $allowed)))->toHaveCount(count($allowed));
})->with('booking statuses');

it('reaches every status from requested, where CreateBooking starts', function () {
    $reached = [BookingStatus::Requested];
    for ($i = 0; $i < count($reached); $i++) {
        foreach ($reached[$i]->allowedTransitions() as $next) {
            if (! in_array($next, $reached, true)) {
                $reached[] = $next;
            }
        }
    }

    expect($reached)->toHaveCount(count(BookingStatus::cases()));
});

it('cannot come back to requested once it has left', function (BookingStatus $from) {
    expect($from->canTransitionTo(BookingStatus::Requested))->toBeFalse();
})->with('booking statuses');

it('has Dutch labels', function () {
    expect(array_map(fn (BookingStatus $s): string => $s->label(), BookingStatus::cases()))
        ->toBe(['aangevraagd', 'bevestigd', 'geannuleerd', 'voltooid', 'niet verschenen']);
});
