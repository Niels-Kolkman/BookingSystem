<?php

namespace App\Enums;

/**
 * Lifecycle of a booking. See docs/erd.md, "Status transitions" (ISSUE-18):
 *
 *     requested ──> confirmed ──> completed
 *         │             │    └──> no_show
 *         └──> cancelled <┘
 *
 * - requested: made by the customer (CreateBooking), awaiting confirmation;
 * - confirmed: the salon confirmed it;
 * - cancelled, completed, no_show: final, nothing follows them.
 *
 * Only a confirmed booking can be completed or a no-show: a request that
 * was never confirmed is cancelled instead. Booking enforces these rules on
 * every save; see Booking::transitionTo().
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

    /**
     * The statuses this one may change into. Staying the same is not a
     * transition and is not listed.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Cancelled, self::Completed, self::NoShow],
            self::Cancelled, self::Completed, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Nothing follows a final status.
     */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Dutch name for the user.
     */
    public function label(): string
    {
        return match ($this) {
            self::Requested => 'aangevraagd',
            self::Confirmed => 'bevestigd',
            self::Cancelled => 'geannuleerd',
            self::Completed => 'voltooid',
            self::NoShow => 'niet verschenen',
        };
    }
}
