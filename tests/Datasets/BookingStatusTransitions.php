<?php

use App\Enums\BookingStatus;

/*
 * Every booking status on its own, named by its value.
 */
dataset('booking statuses', array_combine(
    array_map(fn (BookingStatus $s): string => $s->value, BookingStatus::cases()),
    array_map(fn (BookingStatus $s): array => [$s], BookingStatus::cases()),
));

/*
 * Every pair of booking statuses, with whether the transition is allowed
 * (ISSUE-18). Written out in full rather than derived from
 * BookingStatus::allowedTransitions(), so a change to the state machine
 * must be made here too. Used by the Unit and Feature transition tests.
 */
dataset('status transitions', [
    'requested -> requested' => [BookingStatus::Requested, BookingStatus::Requested, false],
    'requested -> confirmed' => [BookingStatus::Requested, BookingStatus::Confirmed, true],
    'requested -> cancelled' => [BookingStatus::Requested, BookingStatus::Cancelled, true],
    'requested -> completed' => [BookingStatus::Requested, BookingStatus::Completed, false],
    'requested -> no_show' => [BookingStatus::Requested, BookingStatus::NoShow, false],

    'confirmed -> requested' => [BookingStatus::Confirmed, BookingStatus::Requested, false],
    'confirmed -> confirmed' => [BookingStatus::Confirmed, BookingStatus::Confirmed, false],
    'confirmed -> cancelled' => [BookingStatus::Confirmed, BookingStatus::Cancelled, true],
    'confirmed -> completed' => [BookingStatus::Confirmed, BookingStatus::Completed, true],
    'confirmed -> no_show' => [BookingStatus::Confirmed, BookingStatus::NoShow, true],

    'cancelled -> requested' => [BookingStatus::Cancelled, BookingStatus::Requested, false],
    'cancelled -> confirmed' => [BookingStatus::Cancelled, BookingStatus::Confirmed, false],
    'cancelled -> cancelled' => [BookingStatus::Cancelled, BookingStatus::Cancelled, false],
    'cancelled -> completed' => [BookingStatus::Cancelled, BookingStatus::Completed, false],
    'cancelled -> no_show' => [BookingStatus::Cancelled, BookingStatus::NoShow, false],

    'completed -> requested' => [BookingStatus::Completed, BookingStatus::Requested, false],
    'completed -> confirmed' => [BookingStatus::Completed, BookingStatus::Confirmed, false],
    'completed -> cancelled' => [BookingStatus::Completed, BookingStatus::Cancelled, false],
    'completed -> completed' => [BookingStatus::Completed, BookingStatus::Completed, false],
    'completed -> no_show' => [BookingStatus::Completed, BookingStatus::NoShow, false],

    'no_show -> requested' => [BookingStatus::NoShow, BookingStatus::Requested, false],
    'no_show -> confirmed' => [BookingStatus::NoShow, BookingStatus::Confirmed, false],
    'no_show -> cancelled' => [BookingStatus::NoShow, BookingStatus::Cancelled, false],
    'no_show -> completed' => [BookingStatus::NoShow, BookingStatus::Completed, false],
    'no_show -> no_show' => [BookingStatus::NoShow, BookingStatus::NoShow, false],
]);
