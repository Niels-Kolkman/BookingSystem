<?php

namespace App\Enums;

/**
 * Kind of a date-specific deviation from the weekly schedule (ISSUE-10).
 */
enum ScheduleExceptionType: string
{
    /** Time off, leave, sick day: removes hours (whole day or a range). */
    case Unavailable = 'unavailable';

    /** Works on top of the weekly schedule, also on a public holiday. */
    case ExtraHours = 'extra_hours';

    public function label(): string
    {
        return match ($this) {
            self::Unavailable => 'Afwezig',
            self::ExtraHours => 'Extra uren',
        };
    }
}
