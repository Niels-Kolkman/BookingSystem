<?php

namespace App\Scheduling;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A concrete moment range in which an employee cannot take a new booking:
 * [startsAt, until), in UTC. For an existing booking that is
 * `starts_at` up to `blocked_until`, so its buffer is included.
 */
final readonly class BusyPeriod
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $until,
    ) {
        if ($until <= $startsAt) {
            throw new InvalidArgumentException('A busy period must end after it starts.');
        }
    }

    public static function fromBooking(Booking $booking): self
    {
        return new self($booking->starts_at->utc(), $booking->blocked_until->utc());
    }

    /**
     * Same rule as the overlap check in docs/erd.md:
     * existing.starts_at < new.blocked_until AND existing.blocked_until > new.starts_at.
     */
    public function overlaps(CarbonImmutable $startsAt, CarbonImmutable $blockedUntil): bool
    {
        return $this->startsAt < $blockedUntil && $this->until > $startsAt;
    }
}
