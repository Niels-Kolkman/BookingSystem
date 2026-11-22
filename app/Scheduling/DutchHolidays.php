<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Dutch public holidays (ISSUE-10), calculated instead of stored so every
 * year works without maintenance. Which of them close the business is
 * configured in config/holidays.php.
 *
 * Rules: fixed dates, Easter-based dates (Gregorian computus) and Koningsdag
 * on 27 April, or 26 April when the 27th is a Sunday (rule since 2014).
 */
final class DutchHolidays
{
    public const TIMEZONE = 'Europe/Amsterdam';

    /**
     * All holidays of a year, in date order.
     *
     * @return list<Holiday>
     */
    public static function forYear(int $year): array
    {
        $easter = self::easterSunday($year);
        $fixed = fn (int $month, int $day): CarbonImmutable => CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::TIMEZONE);

        $kingsDay = $fixed(4, 27);
        if ($kingsDay->isSunday()) {
            $kingsDay = $kingsDay->subDay();
        }

        $holidays = [
            new Holiday('nieuwjaarsdag', 'Nieuwjaarsdag', $fixed(1, 1)),
            new Holiday('goede_vrijdag', 'Goede Vrijdag', $easter->subDays(2)),
            new Holiday('eerste_paasdag', 'Eerste Paasdag', $easter),
            new Holiday('tweede_paasdag', 'Tweede Paasdag', $easter->addDay()),
            new Holiday('koningsdag', 'Koningsdag', $kingsDay),
            new Holiday('bevrijdingsdag', 'Bevrijdingsdag', $fixed(5, 5)),
            new Holiday('hemelvaartsdag', 'Hemelvaartsdag', $easter->addDays(39)),
            new Holiday('eerste_pinksterdag', 'Eerste Pinksterdag', $easter->addDays(49)),
            new Holiday('tweede_pinksterdag', 'Tweede Pinksterdag', $easter->addDays(50)),
            new Holiday('eerste_kerstdag', 'Eerste Kerstdag', $fixed(12, 25)),
            new Holiday('tweede_kerstdag', 'Tweede Kerstdag', $fixed(12, 26)),
        ];

        usort($holidays, fn (Holiday $a, Holiday $b): int => $a->date <=> $b->date);

        return $holidays;
    }

    /**
     * The holiday on a local date, or null. Only the calendar date of
     * `$date` is used, so pass a date in Europe/Amsterdam.
     */
    public static function on(DateTimeInterface $date): ?Holiday
    {
        $day = $date->format('Y-m-d');

        foreach (self::forYear((int) $date->format('Y')) as $holiday) {
            if ($holiday->date->format('Y-m-d') === $day) {
                return $holiday;
            }
        }

        return null;
    }

    /**
     * Holidays from `$from` up to and including `$until`, in date order.
     *
     * @return list<Holiday>
     */
    public static function between(DateTimeInterface $from, DateTimeInterface $until): array
    {
        $start = $from->format('Y-m-d');
        $end = $until->format('Y-m-d');
        $found = [];

        for ($year = (int) $from->format('Y'); $year <= (int) $until->format('Y'); $year++) {
            foreach (self::forYear($year) as $holiday) {
                $day = $holiday->date->format('Y-m-d');
                if ($day >= $start && $day <= $end) {
                    $found[] = $holiday;
                }
            }
        }

        return $found;
    }

    /**
     * Easter Sunday in the Gregorian calendar (anonymous Gregorian algorithm,
     * Meeus/Jones/Butcher). Avoids a dependency on ext-calendar.
     */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::TIMEZONE);
    }
}
