<?php

namespace App\Bookings;

use App\Enums\BookingStatus;
use LogicException;

/**
 * A booking was asked to change to a status that may not follow its
 * current one (ISSUE-18, BookingStatus::allowedTransitions()).
 *
 * A LogicException: the actions (CancelBooking, ...) check through
 * BookingPolicy before they change a status, so this means a bug, not a
 * user error. The message is Dutch, like the other refusals.
 */
final class InvalidStatusTransition extends LogicException
{
    private function __construct(
        public readonly BookingStatus $from,
        public readonly BookingStatus $to,
    ) {
        parent::__construct(
            "De status van een afspraak kan niet van '{$from->label()}' naar '{$to->label()}' gaan."
        );
    }

    public static function between(BookingStatus $from, BookingStatus $to): self
    {
        return new self($from, $to);
    }
}
