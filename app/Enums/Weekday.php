<?php

namespace App\Enums;

use DateTimeInterface;

/**
 * Day of the week for the recurring schedule (ISSUE-9). The values follow
 * ISO-8601 (1 = Monday ... 7 = Sunday), the same as PHP's `N` date format
 * and Carbon's `isoWeekday()`, so a date maps to its weekday without lookup
 * tables.
 */
enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    /**
     * The weekday of a date, in that date's own time zone. Convert to
     * Europe/Amsterdam first when starting from a UTC moment.
     */
    public static function fromDate(DateTimeInterface $date): self
    {
        return self::from((int) $date->format('N'));
    }

    public function label(): string
    {
        return match ($this) {
            self::Monday => 'maandag',
            self::Tuesday => 'dinsdag',
            self::Wednesday => 'woensdag',
            self::Thursday => 'donderdag',
            self::Friday => 'vrijdag',
            self::Saturday => 'zaterdag',
            self::Sunday => 'zondag',
        };
    }
}
