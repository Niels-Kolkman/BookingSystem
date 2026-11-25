<?php

use App\Bookings\BookingCalendarEvent;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use Carbon\CarbonImmutable;

use function Pest\Laravel\travelTo;

/*
 * ISSUE-19: the .ics file attached to the confirmation mail (RFC 5545).
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['app.url' => 'https://salon.example', 'app.name' => 'Salon']);
});

/**
 * A booking at a local start time, 30 minutes plus a 15-minute buffer.
 *
 * @param  array<string, mixed>  $attributes
 */
function icsBooking(string $localStart = '2026-12-01 09:15', array $attributes = []): Booking
{
    $startsAt = CarbonImmutable::parse($localStart, 'Europe/Amsterdam')->utc();

    return Booking::factory()
        ->for(Service::factory()->state(['name' => 'Knippen']))
        ->for(Employee::factory()->state(['display_name' => 'Sanne']))
        ->create([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'blocked_until' => $startsAt->addMinutes(45),
            'status' => BookingStatus::Requested,
            ...$attributes,
        ]);
}

/**
 * Content lines with folding undone (RFC 5545 3.1), keyed by property name.
 *
 * @return array<string, string>
 */
function icsProperties(string $ics): array
{
    $unfolded = str_replace("\r\n ", '', $ics);
    $properties = [];
    foreach (explode("\r\n", rtrim($unfolded, "\r\n")) as $line) {
        [$name, $value] = explode(':', $line, 2);
        $properties[$name] ??= $value;
    }

    return $properties;
}

it('is a single event in a calendar, with CRLF line endings', function () {
    $ics = BookingCalendarEvent::for(icsBooking());

    $lines = explode("\r\n", $ics);
    expect($lines[0])->toBe('BEGIN:VCALENDAR')
        ->and($lines[1])->toBe('VERSION:2.0')
        ->and($ics)->toEndWith("END:VEVENT\r\nEND:VCALENDAR\r\n")
        ->and(substr_count($ics, 'BEGIN:VEVENT'))->toBe(1)
        ->and(preg_match("/(?<!\r)\n/", $ics))->toBe(0);
});

it('has the required properties', function () {
    $booking = icsBooking();
    $properties = icsProperties(BookingCalendarEvent::for($booking));

    expect($properties)->toMatchArray([
        'PRODID' => '-//Salon//Boekingen//NL',
        'METHOD' => 'PUBLISH',
        'UID' => "booking-{$booking->id}@salon.example",
        'SEQUENCE' => '0',
        'DTSTAMP' => '20261123T090000Z',
        'SUMMARY' => 'Knippen bij Sanne',
        'URL' => route('bookings.show', $booking),
    ]);
});

it('writes the appointment in UTC, without the buffer', function () {
    $properties = icsProperties(BookingCalendarEvent::for(icsBooking('2026-12-01 09:15')));

    // 09:15 winter time (UTC+1) is 08:15 UTC; the event ends at 09:45 local.
    expect($properties['DTSTART'])->toBe('20261201T081500Z')
        ->and($properties['DTEND'])->toBe('20261201T084500Z');
});

it('writes summer time in UTC as well', function () {
    $properties = icsProperties(BookingCalendarEvent::for(icsBooking('2027-06-01 09:15')));

    expect($properties['DTSTART'])->toBe('20270601T071500Z');
});

it('keeps the two 02:30s of the night the clock goes back apart', function () {
    $first = CarbonImmutable::parse('2026-10-25 00:30', 'UTC');  // 02:30 summer time
    $second = CarbonImmutable::parse('2026-10-25 01:30', 'UTC'); // 02:30 winter time

    $a = icsProperties(BookingCalendarEvent::for(icsBooking(attributes: ['starts_at' => $first, 'ends_at' => $first->addMinutes(30), 'blocked_until' => $first->addMinutes(30)])));
    $b = icsProperties(BookingCalendarEvent::for(icsBooking(attributes: ['starts_at' => $second, 'ends_at' => $second->addMinutes(30), 'blocked_until' => $second->addMinutes(30)])));

    expect($a['DTSTART'])->toBe('20261025T003000Z')
        ->and($b['DTSTART'])->toBe('20261025T013000Z')
        ->and($a['DESCRIPTION'])->toContain('02:30 (zomertijd)')
        ->and($b['DESCRIPTION'])->toContain('02:30 (wintertijd)');
});

it('maps the booking status to the event status', function (BookingStatus $status, string $expected) {
    $properties = icsProperties(BookingCalendarEvent::for(icsBooking(attributes: ['status' => $status])));

    expect($properties['STATUS'])->toBe($expected);
})->with([
    'requested' => [BookingStatus::Requested, 'TENTATIVE'],
    'confirmed' => [BookingStatus::Confirmed, 'CONFIRMED'],
    'cancelled' => [BookingStatus::Cancelled, 'CANCELLED'],
    'completed' => [BookingStatus::Completed, 'CONFIRMED'],
    'no-show' => [BookingStatus::NoShow, 'CONFIRMED'],
]);

it('describes the appointment in Dutch, local time', function () {
    $properties = icsProperties(BookingCalendarEvent::for(icsBooking(attributes: ['notes' => 'Graag kort'])));

    expect($properties['DESCRIPTION'])->toBe(
        'Dienst: Knippen\nMedewerker: Sanne\nTijd: 01-12-2026 09:15 - 09:45\nStatus: aangevraagd\nOpmerking: Graag kort'
    );
});

it('leaves out the notes line when there are none', function () {
    $properties = icsProperties(BookingCalendarEvent::for(icsBooking()));

    expect($properties['DESCRIPTION'])->not->toContain('Opmerking');
});

it('escapes special characters in text values', function () {
    $booking = icsBooking(attributes: ['notes' => "a;b,c\\d\r\nnieuwe regel\x07"]);
    $properties = icsProperties(BookingCalendarEvent::for($booking));

    expect($properties['DESCRIPTION'])->toEndWith('Opmerking: a\;b\,c\\\\d\nnieuwe regel');
});

it('cannot be used to inject properties through the notes', function () {
    $ics = BookingCalendarEvent::for(icsBooking(attributes: ['notes' => "x\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nSUMMARY:nep"]));

    expect(substr_count($ics, "\r\nBEGIN:VEVENT"))->toBe(1)
        ->and(substr_count($ics, "\r\nSUMMARY:"))->toBe(1);
});

it('folds long lines at 75 octets without splitting a character', function () {
    $notes = str_repeat('Één crème brûlée, graag! ', 12);
    $ics = BookingCalendarEvent::for(icsBooking(attributes: ['notes' => trim($notes)]));

    foreach (explode("\r\n", $ics) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)
            ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }

    expect(icsProperties($ics)['DESCRIPTION'])->toEndWith('Opmerking: '.str_replace(',', '\,', trim($notes)));
});

it('keeps the same UID for the same booking and differs per booking', function () {
    $booking = icsBooking();
    $other = icsBooking('2026-12-01 10:15');

    expect(icsProperties(BookingCalendarEvent::for($booking))['UID'])->toBe(BookingCalendarEvent::uid($booking))
        ->and(BookingCalendarEvent::uid($booking))->not->toBe(BookingCalendarEvent::uid($other));
});

it('falls back to localhost in the UID when the app URL has no host', function () {
    config(['app.url' => '']);

    expect(BookingCalendarEvent::uid(icsBooking()))->toEndWith('@localhost');
});

it('uses only the host of the app URL in the UID, without port or path', function () {
    config(['app.url' => 'https://salon.example:8443/boeken']);
    $booking = icsBooking();

    expect(BookingCalendarEvent::uid($booking))->toBe("booking-{$booking->id}@salon.example");
});

it('writes the given sequence, so a later file for the booking replaces the event', function () {
    $booking = icsBooking();

    $first = icsProperties(BookingCalendarEvent::for($booking));
    $later = icsProperties(BookingCalendarEvent::for($booking, 3));

    expect($first['SEQUENCE'])->toBe('0')
        ->and($later['SEQUENCE'])->toBe('3')
        ->and($later['UID'])->toBe($first['UID']);
});

it('stamps the file with the moment it is made, in UTC, also in summer time', function () {
    $booking = icsBooking('2027-08-01 09:15');
    travelTo(CarbonImmutable::parse('2027-07-15 14:05:09', 'Europe/Amsterdam'));

    expect(icsProperties(BookingCalendarEvent::for($booking))['DTSTAMP'])->toBe('20270715T120509Z');
});

it('escapes the app, service and employee names', function () {
    config(['app.name' => 'Kapper; Knip, & Co']);
    $booking = icsBooking();
    $booking->service->update(['name' => 'Wassen, knippen']);
    $booking->employee->update(['display_name' => 'Sanne; Jan']);

    $properties = icsProperties(BookingCalendarEvent::for($booking->fresh()));

    expect($properties['PRODID'])->toBe('-//Kapper\; Knip\, & Co//Boekingen//NL')
        ->and($properties['SUMMARY'])->toBe('Wassen\, knippen bij Sanne\; Jan')
        ->and($properties['DESCRIPTION'])->toStartWith('Dienst: Wassen\, knippen\nMedewerker: Sanne\; Jan\n');
});

it('leaves out the notes line when the notes are only whitespace', function () {
    $properties = icsProperties(BookingCalendarEvent::for(icsBooking(attributes: ['notes' => "  \n "])));

    expect($properties['DESCRIPTION'])->not->toContain('Opmerking');
});

it('does not fold a line of exactly 75 octets, but folds one of 76', function () {
    // "SUMMARY:" (8) + service + " bij Sanne" (10).
    $booking = icsBooking();
    $booking->service->update(['name' => str_repeat('a', 57)]);
    $exact = BookingCalendarEvent::for($booking->fresh());

    $booking->service->update(['name' => str_repeat('a', 58)]);
    $over = BookingCalendarEvent::for($booking->fresh());

    expect($exact)->toContain("\r\nSUMMARY:".str_repeat('a', 57)." bij Sanne\r\n")
        ->and($over)->toContain("\r\nSUMMARY:".str_repeat('a', 58).' bij Sann'."\r\n e\r\n");
});

it('folds before a multi-byte character that would cross the 75th octet', function () {
    // "SUMMARY:Knippen bij " is 20 octets; 54 more make 74, then "é" (2 octets).
    $booking = icsBooking();
    $booking->employee->update(['display_name' => str_repeat('a', 54).'éb']);

    $ics = BookingCalendarEvent::for($booking->fresh());

    expect($ics)->toContain("\r\nSUMMARY:Knippen bij ".str_repeat('a', 54)."\r\n éb\r\n");
});

it('keeps continuation lines at 75 octets including the leading space', function () {
    $ics = BookingCalendarEvent::for(icsBooking(attributes: ['notes' => str_repeat('x', 300)]));

    $continuations = array_filter(explode("\r\n", $ics), fn (string $line): bool => str_starts_with($line, ' '));

    expect($continuations)->not->toBeEmpty();
    foreach (array_slice($continuations, 0, -1) as $line) {
        expect(strlen($line))->toBe(75);
    }
});
