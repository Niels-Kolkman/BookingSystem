<?php

namespace App\Enums;

/**
 * Lifecycle of a booking. See docs/erd.md.
 *
 * Which transitions are allowed is defined in ISSUE-18.
 */
enum BookingStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';

    /**
     * Statuses whose time range is taken (docs/erd.md, "Which bookings block
     * a slot"): requested and confirmed. Cancelled frees the time; completed
     * and no-show lie in the past.
     *
     * @return list<self>
     */
    public static function blocking(): array
    {
        return [self::Requested, self::Confirmed];
    }

    public function blocksTime(): bool
    {
        return in_array($this, self::blocking(), true);
    }
}
