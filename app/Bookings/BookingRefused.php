<?php

namespace App\Bookings;

use RuntimeException;

/**
 * Why CreateBooking refused a booking (ISSUE-12), or RescheduleBooking a new
 * time for one (ISSUE-17). The message is Dutch and
 * meant for the customer; `field` is the input it belongs to, so a
 * controller can show it next to that field.
 */
final class BookingRefused extends RuntimeException
{
    public const REASON_NOT_BOOKABLE = 'not_bookable';

    public const REASON_EMPLOYEE_BUSY = 'employee_busy';

    public const REASON_CUSTOMER_BUSY = 'customer_busy';

    public const REASON_NOT_OFFERED = 'not_offered';

    public const REASON_SAME_SLOT = 'same_slot';

    private function __construct(
        public readonly string $reason,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Whether the time was taken by another booking (of the employee or of
     * the customer) rather than never being bookable. The booking page
     * (ISSUE-15) answers these with 409 and reloads the free times.
     */
    public function isConflict(): bool
    {
        return in_array($this->reason, [self::REASON_EMPLOYEE_BUSY, self::REASON_CUSTOMER_BUSY], true);
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

    /**
     * The customer already has another appointment at this time. Worded for
     * the customer, or for an admin moving someone else's booking (ISSUE-17).
     */
    public static function customerBusy(bool $toCustomer = true): self
    {
        return new self(self::REASON_CUSTOMER_BUSY, 'starts_at', $toCustomer
            ? 'Je hebt op dit tijdstip al een andere afspraak.'
            : 'De klant heeft op dit tijdstip al een andere afspraak.');
    }

    /** A booking is moved to the time it already has (ISSUE-17). */
    public static function sameSlot(): self
    {
        return new self(self::REASON_SAME_SLOT, 'starts_at', 'De afspraak staat al op dit tijdstip. Kies een ander tijdstip.');
    }

    /** Not a free slot: outside working hours, off the interval grid or in the past. */
    public static function notOffered(): self
    {
        return new self(self::REASON_NOT_OFFERED, 'starts_at', 'Dit tijdstip is niet beschikbaar. Kies een van de aangeboden tijdstippen.');
    }
}
