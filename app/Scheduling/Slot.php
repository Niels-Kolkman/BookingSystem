<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

/**
 * A free start time for one booking (ISSUE-11). All moments are UTC, ready
 * to be stored on a booking (ISSUE-12):
 * - `startsAt`: the appointment starts;
 * - `endsAt`: startsAt + service duration;
 * - `blockedUntil`: endsAt + service buffer.
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public CarbonImmutable $blockedUntil,
    ) {}

    /**
     * The start in Europe/Amsterdam, for display.
     */
    public function localStart(): CarbonImmutable
    {
        return $this->startsAt->setTimezone(DutchHolidays::TIMEZONE);
    }

    /**
     * Local start time, e.g. "09:15".
     */
    public function label(): string
    {
        return $this->localStart()->format('H:i');
    }
}
