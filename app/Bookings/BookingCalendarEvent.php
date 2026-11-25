<?php

namespace App\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Scheduling\LocalTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A booking as an iCalendar file (RFC 5545), attached to the confirmation
 * mail (ISSUE-19) so the customer can put the appointment in their agenda.
 *
 * - Times are written in UTC ("20261201T080000Z"). Every calendar app shows
 *   them in the customer's own zone, and no VTIMEZONE block is needed, so
 *   the daylight-saving nights need no special care (ISSUE-14).
 * - The event lasts from starts_at to ends_at: the appointment itself; the
 *   buffer after it is the employee's time, not the customer's.
 * - The UID is stable per booking, so a later file for the same booking
 *   (e.g. after moving it) updates the event instead of adding a second
 *   one, as long as its SEQUENCE is higher.
 * - STATUS is TENTATIVE while the booking is only requested, CONFIRMED once
 *   the salon confirmed it, CANCELLED for a cancelled booking.
 */
final class BookingCalendarEvent
{
    public const MIME_TYPE = 'text/calendar; charset=utf-8';

    public const FILENAME = 'afspraak.ics';

    /** RFC 5545 3.1: lines longer than 75 octets are folded. */
    private const MAX_LINE_OCTETS = 75;

    public static function for(Booking $booking, int $sequence = 0): string
    {
        $booking->loadMissing(['service', 'employee']);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.self::text((string) config('app.name')).'//Boekingen//NL',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.self::uid($booking),
            'SEQUENCE:'.$sequence,
            'DTSTAMP:'.self::utc(CarbonImmutable::now()),
            'DTSTART:'.self::utc($booking->starts_at),
            'DTEND:'.self::utc($booking->ends_at),
            'SUMMARY:'.self::text($booking->service->name.' bij '.$booking->employee->display_name),
            'DESCRIPTION:'.self::text(self::description($booking)),
            'STATUS:'.self::status($booking->status),
            'URL:'.route('bookings.show', $booking),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * Unique and stable for this booking in this installation.
     */
    public static function uid(Booking $booking): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return 'booking-'.$booking->id.'@'.(is_string($host) && $host !== '' ? $host : 'localhost');
    }

    private static function description(Booking $booking): string
    {
        $lines = [
            'Dienst: '.$booking->service->name,
            'Medewerker: '.$booking->employee->display_name,
            'Tijd: '.LocalTime::dateTime($booking->starts_at).' - '.LocalTime::time($booking->ends_at),
            'Status: '.$booking->status->label(),
        ];

        if (filled($booking->notes)) {
            $lines[] = 'Opmerking: '.$booking->notes;
        }

        return implode("\n", $lines);
    }

    private static function status(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Requested => 'TENTATIVE',
            BookingStatus::Cancelled => 'CANCELLED',
            BookingStatus::Confirmed, BookingStatus::Completed, BookingStatus::NoShow => 'CONFIRMED',
        };
    }

    private static function utc(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->utc()->format('Ymd\THis\Z');
    }

    /**
     * Escape a TEXT value (RFC 5545 3.3.11): backslash, semicolon, comma and
     * newlines. Other control characters are not allowed and are dropped.
     */
    private static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);

        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value);
    }

    /**
     * Fold a content line at 75 octets, never inside a UTF-8 character:
     * the rest continues on the next line after a single space.
     */
    private static function fold(string $line): string
    {
        $folded = [];
        $current = '';
        $limit = self::MAX_LINE_OCTETS;

        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $folded[] = $current;
                $current = '';
                // Continuation lines start with a space, which counts too.
                $limit = self::MAX_LINE_OCTETS - 1;
            }
            $current .= $char;
        }
        $folded[] = $current;

        return implode("\r\n ", $folded);
    }
}
