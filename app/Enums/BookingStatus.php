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
}
