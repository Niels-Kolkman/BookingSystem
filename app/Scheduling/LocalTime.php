<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use UnexpectedValueException;

/**
 * The one place that turns local wall-clock time into moments and back
 * (ISSUE-14, docs/decisions.md section 3). Moments are stored in UTC; the
 * business and its customers live in Europe/Amsterdam.
 *
 * The daylight-saving switch nights, both on a Sunday:
 * - last Sunday of March, the clock jumps from 02:00 to 03:00: wall-clock
 *   times 02:00-02:59 do not exist (the "gap");
 * - last Sunday of October, the clock goes back from 03:00 to 02:00:
 *   02:00-02:59 happens twice, first in summer time (UTC+2), then in winter
 *   time (UTC+1).
 *
 * Rules, see at():
 * - a wall-clock time in the gap means the moment the clock jumps (03:00
 *   summer time). Working "until 02:30" ends at the jump, working "from
 *   02:30" starts at it, so no time is offered that the clock never shows;
 * - a wall-clock time in the repeated hour means its first occurrence
 *   (summer time), the moment the clock first reads that time;
 * - durations are real elapsed minutes, so a 60-minute appointment always
 *   takes 60 minutes, even when the clock says otherwise.
 *
 * Display (time(), dateTime()) adds "(zomertijd)" or "(wintertijd)" to a
 * time in the repeated hour, so the two 02:30s can be told apart.
 */
final class LocalTime
{
    public const TIMEZONE = 'Europe/Amsterdam';

    private const MINUTES_PER_DAY = 1440;

    /**
     * The UTC moment at a wall-clock time on a local date.
     *
     * @param  DateTimeInterface  $date  only its calendar date is used
     * @param  int  $minutes  minutes since local midnight, 0-1440; 1440 is the next local midnight
     */
    public static function at(DateTimeInterface $date, int $minutes): CarbonImmutable
    {
        if ($minutes < 0 || $minutes > self::MINUTES_PER_DAY) {
            throw new UnexpectedValueException("Invalid minute of the day {$minutes}.");
        }

        $midnight = self::midnight($date);
        if ($minutes === self::MINUTES_PER_DAY) {
            return $midnight->addDay()->utc();
        }

        $wanted = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

        // PHP resolves a repeated time to its first occurrence, as wanted.
        $local = $midnight->setTime(intdiv($minutes, 60), $minutes % 60);
        if ($local->format('H:i') === $wanted) {
            return $local->utc();
        }

        // In the gap PHP moves the time forward by the gap's length (02:30
        // becomes 03:30). The clock skips the gap, so use the jump itself.
        return self::lastTransitionBefore($local);
    }

    /**
     * Local midnight at the start of the calendar date of `$date`.
     */
    public static function midnight(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), self::TIMEZONE)
            ?: throw new UnexpectedValueException('Invalid date.');
    }

    /**
     * A local date (midnight, Europe/Amsterdam) from a "Y-m-d" string, e.g.
     * from a query string. Null when it is not a string, not shaped like a
     * date or not a real date (2026-02-30).
     */
    public static function parseDate(mixed $value): ?CarbonImmutable
    {
        // Carbon throws on anything that is not shaped like a date.
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * A moment in Europe/Amsterdam.
     */
    public static function toLocal(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::TIMEZONE);
    }

    /**
     * Whether the local wall-clock time of this moment happens twice that
     * night (the hour the clock goes back).
     */
    public static function isAmbiguous(DateTimeInterface $moment): bool
    {
        $local = self::toLocal($moment);
        $wall = $local->format('Y-m-d H:i');

        return $local->subHour()->format('Y-m-d H:i') === $wall
            || $local->addHour()->format('Y-m-d H:i') === $wall;
    }

    /**
     * Local time for display: "09:15", or "02:30 (zomertijd)" /
     * "02:30 (wintertijd)" in the repeated hour.
     */
    public static function time(DateTimeInterface $moment): string
    {
        $local = self::toLocal($moment);

        return $local->format('H:i').self::suffix($local);
    }

    /**
     * Local date and time for display: "01-12-2026 09:15", with the same
     * suffix as time() in the repeated hour.
     */
    public static function dateTime(DateTimeInterface $moment): string
    {
        $local = self::toLocal($moment);

        return $local->format('d-m-Y H:i').self::suffix($local);
    }

    private static function suffix(CarbonImmutable $local): string
    {
        if (! self::isAmbiguous($local)) {
            return '';
        }

        return $local->isDST() ? ' (zomertijd)' : ' (wintertijd)';
    }

    /**
     * The UTC moment of the last clock change at or before `$moment`.
     */
    private static function lastTransitionBefore(CarbonImmutable $moment): CarbonImmutable
    {
        $timestamp = $moment->getTimestamp();
        $transitions = (new DateTimeZone(self::TIMEZONE))->getTransitions($timestamp - 86400, $timestamp);

        // The first entry is the state at the start of the window, not a change.
        if (count($transitions) < 2) {
            throw new UnexpectedValueException('No clock change found before '.$moment->toIso8601String().'.');
        }

        return CarbonImmutable::createFromTimestamp($transitions[array_key_last($transitions)]['ts'], 'UTC');
    }
}
