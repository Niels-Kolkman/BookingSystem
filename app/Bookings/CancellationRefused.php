<?php

namespace App\Bookings;

use RuntimeException;

/**
 * Why CancelBooking refused a cancellation (ISSUE-16). The message is Dutch
 * and meant for the user. `notFound` is set when the user may not see the
 * booking at all, so a controller can answer 404, as for viewing it.
 */
final class CancellationRefused extends RuntimeException
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
