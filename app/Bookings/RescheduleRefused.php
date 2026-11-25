<?php

namespace App\Bookings;

use RuntimeException;

/**
 * Why RescheduleBooking refused to move a booking at all (ISSUE-17): the
 * user may not move it (BookingPolicy::reschedule()). A new time that is
 * not free is refused with BookingRefused instead, as for a new booking.
 *
 * The message is Dutch and meant for the user. `notFound` is set when the
 * user may not see the booking at all, so a controller can answer 404, as
 * for viewing it.
 */
final class RescheduleRefused extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly bool $notFound,
    ) {
        parent::__construct($message);
    }

    public static function because(string $message, bool $notFound = false): self
    {
        return new self($message, $notFound);
    }
}
