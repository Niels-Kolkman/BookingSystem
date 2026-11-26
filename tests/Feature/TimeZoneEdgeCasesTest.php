<?php

use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-14 edge cases beyond TimeZoneTest:
 * - StoresDatesInUtc with strings, offsets, mutable Carbons and updates;
 * - the Booking query scopes given Amsterdam-zoned moments;
 * - lists ordered by real time, not by wall-clock time, on the October night;
 * - starts_at input rules and a wall-clock time in the skipped March hour.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-03-01 12:00', 'Europe/Amsterdam'));
    config(['booking.slot_interval' => 30]);
});

function tzRawStart(Booking $booking): string
{
    return substr((string) DB::table('bookings')->where('id', $booking->id)->value('starts_at'), 0, 19);
}

/**
 * @return array{Employee, Service}
 */
function tzNightEmployee(): array
{
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Sunday, '01:00', '04:00')->create();

    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
    $employee->services()->attach($service);

    return [$employee, $service];
}

function tzBookingAt(CarbonImmutable $start, array $attributes = []): Booking
{
    return Booking::factory()->create([
        'starts_at' => $start,
        'ends_at' => $start->addMinutes(30),
        'blocked_until' => $start->addMinutes(30),
        ...$attributes,
    ]);
}

describe('StoresDatesInUtc', function () {
    it('stores a string without a zone as UTC, unchanged', function () {
        $booking = tzBookingAt(CarbonImmutable::parse('2026-07-01 09:00', 'UTC'), ['starts_at' => '2026-07-01 09:00:00']);

        expect(tzRawStart($booking))->toBe('2026-07-01 09:00:00')
            ->and($booking->fresh()?->starts_at->format('H:i e'))->toBe('09:00 UTC');
    });

    it('converts a mutable Carbon in Amsterdam and leaves the given Carbon alone', function () {
        // The first 02:30 (summer time), as a mutable Carbon in Amsterdam.
        // Built from an offset: PHP parses the bare string "02:30" on this
        // night as the second occurrence (+01:00).
        $local = Carbon::parse('2026-10-25T02:30:00+02:00')->setTimezone('Europe/Amsterdam');

        $booking = tzBookingAt(CarbonImmutable::parse('2026-10-25 00:30', 'UTC'), ['starts_at' => $local]);

        expect(tzRawStart($booking))->toBe('2026-10-25 00:30:00')
            ->and($local->format('Y-m-d H:i P'))->toBe('2026-10-25 02:30 +02:00');
    });

    it('converts on update as well as on create', function () {
        $booking = tzBookingAt(CarbonImmutable::parse('2026-07-01 07:00', 'UTC'));

        // Booking times are guarded (only CreateBooking sets them), so forceFill.
        $booking->forceFill(['starts_at' => CarbonImmutable::parse('2026-10-25T02:30:00+01:00')])->save();

        expect(tzRawStart($booking))->toBe('2026-10-25 01:30:00');
    });

    it('reads every stored moment back in UTC', function () {
        $booking = tzBookingAt(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Amsterdam'))->fresh();

        expect($booking?->starts_at->getTimezone()->getName())->toBe('UTC')
            ->and($booking?->ends_at->getTimezone()->getName())->toBe('UTC')
            ->and($booking?->blocked_until->getTimezone()->getName())->toBe('UTC');
    });

    it('does not treat an Amsterdam and a UTC Carbon of the same moment as a change', function () {
        $booking = tzBookingAt(CarbonImmutable::parse('2026-07-01 07:00', 'UTC'))->fresh();

        $booking?->setAttribute('starts_at', CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Amsterdam'));

        expect($booking?->isDirty('starts_at'))->toBeFalse();
    });
});

describe('Booking scopes with Amsterdam-zoned moments', function () {
    it('tells the two October 02:30s apart in blockingBetween', function () {
        $employee = Employee::factory()->create();
        $summer = tzBookingAt(CarbonImmutable::parse('2026-10-25T02:30:00+02:00'), ['employee_id' => $employee->id]);
        $winter = tzBookingAt(CarbonImmutable::parse('2026-10-25T02:30:00+01:00'), ['employee_id' => $employee->id]);

        $found = fn (string $from, string $until): array => Booking::query()
            ->blockingBetween(CarbonImmutable::parse($from), CarbonImmutable::parse($until))
            ->pluck('id')->all();

        expect($found('2026-10-25T02:30:00+02:00', '2026-10-25T03:00:00+02:00'))->toBe([$summer->id])
            ->and($found('2026-10-25T02:30:00+01:00', '2026-10-25T03:00:00+01:00'))->toBe([$winter->id])
            // The real hour between them overlaps neither.
            ->and($found('2026-10-25T03:00:00+02:00', '2026-10-25T02:30:00+01:00'))->toBe([]);
    });

    it('finds a customer\'s appointment in attendedByBetween across the March jump', function () {
        $customer = User::factory()->customer()->create();
        // 01:30-02:00 winter time = 01:30-03:00 on the clock.
        $booking = tzBookingAt(CarbonImmutable::parse('2026-03-29T01:30:00+01:00'), ['customer_id' => $customer->id]);

        $found = fn (string $from, string $until): array => Booking::query()
            ->attendedByBetween($customer, CarbonImmutable::parse($from), CarbonImmutable::parse($until))
            ->pluck('id')->all();

        expect($found('2026-03-29T03:00:00+02:00', '2026-03-29T03:30:00+02:00'))->toBe([])
            ->and($found('2026-03-29T01:45:00+01:00', '2026-03-29T03:15:00+02:00'))->toBe([$booking->id]);
    });
});

describe('order of lists on the October night', function () {
    beforeEach(fn () => travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Europe/Amsterdam')));

    it('lists bookings in real time order, so 02:30 summer time comes before 02:00 winter time', function () {
        $customer = User::factory()->customer()->create();
        $employee = Employee::factory()->create();
        $attributes = ['customer_id' => $customer->id, 'employee_id' => $employee->id];

        // Created in wall-clock order; real order is the other way round.
        tzBookingAt(CarbonImmutable::parse('2026-10-25T02:00:00+01:00'), $attributes);
        tzBookingAt(CarbonImmutable::parse('2026-10-25T02:30:00+02:00'), $attributes);

        $order = ['25-10-2026 02:30 (zomertijd)', '25-10-2026 02:00 (wintertijd)'];

        actingAs($customer)->get(route('bookings.index'))->assertOk()->assertSeeInOrder($order);
        actingAs($employee->user)->get(route('employees.agenda', [$employee, 'date' => '2026-10-25']))->assertOk()->assertSeeInOrder($order);
    });
});

describe('starts_at input', function () {
    it('rejects a time without an offset or with "Z"', function (string $startsAt) {
        [$employee, $service] = tzNightEmployee();

        actingAs(User::factory()->customer()->create())->post(route('bookings.store'), [
            'service_id' => (string) $service->id,
            'employee_id' => (string) $employee->id,
            'starts_at' => $startsAt,
        ])->assertSessionHasErrors(['starts_at' => 'Kies een tijdstip.']);

        expect(Booking::count())->toBe(0);
    })->with([
        'no offset' => ['2026-03-29T03:00:00'],
        'Z' => ['2026-03-29T01:00:00Z'],
        'space instead of T' => ['2026-03-29 03:00:00+02:00'],
    ]);

    it('accepts the same moment written in UTC', function () {
        [$employee, $service] = tzNightEmployee();

        actingAs(User::factory()->customer()->create())->post(route('bookings.store'), [
            'service_id' => (string) $service->id,
            'employee_id' => (string) $employee->id,
            'starts_at' => '2026-03-29T01:00:00+00:00',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Je afspraak op 29-03-2026 om 03:00 is geboekt.');

        expect(tzRawStart(Booking::sole()))->toBe('2026-03-29 01:00:00');
    });

    it('reads a wall-clock time in the skipped hour by its offset, as a real moment', function () {
        [$employee, $service] = tzNightEmployee();

        // 02:30+01:00 never shows on a clock, but it is the moment 01:30 UTC,
        // which the clock shows as 03:30 summer time: an offered slot.
        actingAs(User::factory()->customer()->create())->post(route('bookings.store'), [
            'service_id' => (string) $service->id,
            'employee_id' => (string) $employee->id,
            'starts_at' => '2026-03-29T02:30:00+01:00',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Je afspraak op 29-03-2026 om 03:30 is geboekt.');

        expect(tzRawStart(Booking::sole()))->toBe('2026-03-29 01:30:00');
    });

    it('refuses a moment that is not on the slot grid in real time', function () {
        [$employee, $service] = tzNightEmployee();

        // 02:15 winter time on 25 October is 01:15 UTC, between two slots.
        travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Europe/Amsterdam'));

        actingAs(User::factory()->customer()->create())->post(route('bookings.store'), [
            'service_id' => (string) $service->id,
            'employee_id' => (string) $employee->id,
            'starts_at' => '2026-10-25T02:15:00+01:00',
        ])->assertSessionHasErrors('starts_at');

        expect(Booking::count())->toBe(0);
    });
});
