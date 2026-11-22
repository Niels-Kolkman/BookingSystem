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
        return LocalTime::toLocal($this->startsAt);
    }

    /**
     * Local start time, e.g. "09:15". In the hour that happens twice when
     * the clock goes back: "02:30 (zomertijd)" and "02:30 (wintertijd)".
     */
    public function label(): string
    {
        return LocalTime::time($this->startsAt);
    }
}
