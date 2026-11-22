<?php

namespace App\Bookings;

use RuntimeException;

/**
 * Why CreateBooking refused a booking (ISSUE-12). The message is Dutch and
 * meant for the customer; `field` is the input it belongs to, so a
 * controller can show it next to that field.
 */
final class BookingRefused extends RuntimeException
{
    public const REASON_NOT_BOOKABLE = 'not_bookable';

    public const REASON_EMPLOYEE_BUSY = 'employee_busy';

    public const REASON_CUSTOMER_BUSY = 'customer_busy';

    public const REASON_NOT_OFFERED = 'not_offered';

    private function __construct(
        public readonly string $reason,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Inactive service, or an employee who is inactive or not linked to it. */
    public static function notBookable(): self
    {
        return new self(self::REASON_NOT_BOOKABLE, 'employee_id', 'Deze medewerker kan deze dienst niet uitvoeren. Kies een andere medewerker of dienst.');
    }

    /** The employee already has a booking (including its buffer) in this time. */
    public static function employeeBusy(): self
    {
        return new self(self::REASON_EMPLOYEE_BUSY, 'starts_at', 'Dit tijdstip is helaas net bezet. Kies een ander tijdstip.');
    }

    /** The customer already has another appointment at this time. */
    public static function customerBusy(): self
    {
        return new self(self::REASON_CUSTOMER_BUSY, 'starts_at', 'Je hebt op dit tijdstip al een andere afspraak.');
    }

    /** Not a free slot: outside working hours, off the interval grid or in the past. */
    public static function notOffered(): self
    {
        return new self(self::REASON_NOT_OFFERED, 'starts_at', 'Dit tijdstip is niet beschikbaar. Kies een van de aangeboden tijdstippen.');
    }
}
