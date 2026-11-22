<?php

namespace App\Models\Concerns;

/**
 * Date attributes of a model are written to the database in UTC, whatever
 * time zone the given Carbon is in (ISSUE-14, docs/decisions.md section 3).
 *
 * Laravel writes a Carbon's own wall-clock time without converting it, so
 * without this `$booking->starts_at = CarbonImmutable::parse('2026-10-25
 * 02:30', 'Europe/Amsterdam')` would be stored as 02:30 and read back as
 * 02:30 UTC, two hours off. Strings without a zone are read as UTC (the
 * app time zone, config/app.php).
 *
 * Only for columns that hold moments. Local calendar dates, such as
 * schedule_exceptions.date, must not be converted.
 */
trait StoresDatesInUtc
{
    /**
     * @param  mixed  $value
     * @return string|null
     */
    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->copy()->utc()->format(
            $this->getDateFormat()
        );
    }
}
