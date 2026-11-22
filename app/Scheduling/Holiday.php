<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

/**
 * One Dutch public holiday in a given year, e.g. Koningsdag on 2026-04-27.
 */
final readonly class Holiday
{
    /**
     * @param  string  $key  stable identifier used in config/holidays.php, e.g. "koningsdag"
     * @param  string  $name  Dutch display name, e.g. "Koningsdag"
     * @param  CarbonImmutable  $date  local date (midnight, Europe/Amsterdam)
     */
    public function __construct(
        public string $key,
        public string $name,
        public CarbonImmutable $date,
    ) {}

    /**
     * Whether the business is closed on this holiday (config/holidays.php).
     * Employees can still work on a closed holiday with extra hours.
     */
    public function isClosed(): bool
    {
        return in_array($this->key, (array) config('holidays.closed', []), true);
    }
}
