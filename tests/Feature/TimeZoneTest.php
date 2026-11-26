<?php

use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Scheduling\AvailableSlots;
use App\Scheduling\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-14: times are stored in UTC and shown in Europe/Amsterdam, also on
 * the switch nights. Both nights in 2026 are Sundays:
 * - 2026-03-29: 02:00 jumps to 03:00 (02:00-02:59 does not exist);
 * - 2026-10-25: 03:00 goes back to 02:00 (02:00-02:59 happens twice).
 * The employee works Sunday nights 01:00-04:00; the service takes 30 min
 * without buffer; the interval is 30 min.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-03-01 12:00', 'Europe/Amsterdam'));
    config(['booking.slot_interval' => 30]);
});

/**
 * @return array{Employee, Service}
 */
function nightShiftEmployee(): array
{
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Sunday, '01:00', '04:00')->create();

    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
    $employee->services()->attach($service);

    return [$employee, $service];
}

/**
 * @return list<string>
 */
function offeredLabels(Service $service, Employee $employee, string $date): array
{
    $slots = app(AvailableSlots::class)->for($service->fresh() ?? $service, $employee, CarbonImmutable::parse($date, 'Europe/Amsterdam'));

    return array_map(fn (Slot $slot): string => $slot->label(), $slots);
}

/**
 * The stored column values, exactly as they are in the database.
 *
 * @return array<string, string>
 */
function rawTimes(Booking $booking): array
{
    $row = DB::table('bookings')->where('id', $booking->id)->first(['starts_at', 'ends_at', 'blocked_until']);

    return array_map(fn (string $value): string => substr($value, 0, 19), (array) $row);
}

describe('storage', function () {
    it('runs the application in UTC', function () {
        expect(config('app.timezone'))->toBe('UTC')
            ->and(date_default_timezone_get())->toBe('UTC');
    });

    it('stores a time given in Amsterdam as UTC and reads back the same moment', function () {
        $local = CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Amsterdam');

        $booking = Booking::factory()->create([
            'starts_at' => $local,
            'ends_at' => $local->addMinutes(30),
            'blocked_until' => $local->addMinutes(45),
        ]);

        expect(rawTimes($booking))->toBe([
            'starts_at' => '2026-07-01 07:00:00',
            'ends_at' => '2026-07-01 07:30:00',
            'blocked_until' => '2026-07-01 07:45:00',
        ])->and($booking->fresh()?->starts_at->equalTo($local))->toBeTrue();
    });

    it('stores the two 02:30s of the October night as different UTC moments', function () {
        $first = CarbonImmutable::parse('2026-10-25T02:30:00+02:00');
        $second = CarbonImmutable::parse('2026-10-25T02:30:00+01:00');

        $a = Booking::factory()->create(['starts_at' => $first, 'ends_at' => $first->addMinutes(30), 'blocked_until' => $first->addMinutes(30)]);
        $b = Booking::factory()->create(['starts_at' => $second, 'ends_at' => $second->addMinutes(30), 'blocked_until' => $second->addMinutes(30)]);

        expect(rawTimes($a)['starts_at'])->toBe('2026-10-25 00:30:00')
            ->and(rawTimes($b)['starts_at'])->toBe('2026-10-25 01:30:00');
    });
});

describe('clock jumps forward on 29 March', function () {
    it('offers no time between 02:00 and 03:00', function () {
        [$employee, $service] = nightShiftEmployee();

        expect(offeredLabels($service, $employee, '2026-03-29'))->toBe(['01:00', '01:30', '03:00', '03:30']);
    });

    it('books the first slot after the jump and stores it in UTC', function () {
        [$employee, $service] = nightShiftEmployee();

        actingAs(User::factory()->customer()->create())
            ->post(route('bookings.store'), [
                'service_id' => (string) $service->id,
                'employee_id' => (string) $employee->id,
                'starts_at' => '2026-03-29T03:00:00+02:00',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Je afspraak op 29-03-2026 om 03:00 is geboekt.');

        expect(rawTimes(Booking::sole()))->toBe([
            'starts_at' => '2026-03-29 01:00:00',
            'ends_at' => '2026-03-29 01:30:00',
            'blocked_until' => '2026-03-29 01:30:00',
        ]);
    });

    it('books 01:30 winter time, which ends at 03:00 summer time', function () {
        [$employee, $service] = nightShiftEmployee();
        $customer = User::factory()->customer()->create();

        actingAs($customer)->post(route('bookings.store'), [
            'service_id' => (string) $service->id,
            'employee_id' => (string) $employee->id,
            'starts_at' => '2026-03-29T01:30:00+01:00',
        ])->assertSessionHasNoErrors();

        $booking = Booking::sole();

        actingAs($customer)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('29-03-2026 01:30')
            ->assertSee('- 03:00', false);
    });
});

describe('clock goes back on 25 October', function () {
    beforeEach(fn () => travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Europe/Amsterdam')));

    it('offers the repeated hour twice, labelled by summer and winter time', function () {
        [$employee, $service] = nightShiftEmployee();

        expect(offeredLabels($service, $employee, '2026-10-25'))->toBe([
            '01:00', '01:30',
            '02:00 (zomertijd)', '02:30 (zomertijd)',
            '02:00 (wintertijd)', '02:30 (wintertijd)',
            '03:00', '03:30',
        ]);
    });

    it('books both 02:30s as separate appointments', function () {
        [$employee, $service] = nightShiftEmployee();
        $input = fn (string $startsAt): array => [
            'service_id' => (string) $service->id,
            'employee_id' => (string) $employee->id,
            'starts_at' => $startsAt,
        ];

        actingAs(User::factory()->customer()->create())->post(route('bookings.store'), $input('2026-10-25T02:30:00+02:00'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Je afspraak op 25-10-2026 om 02:30 (zomertijd) is geboekt.');

        // Same wall-clock time an hour later: not an overlap.
        actingAs(User::factory()->customer()->create())->post(route('bookings.store'), $input('2026-10-25T02:30:00+01:00'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Je afspraak op 25-10-2026 om 02:30 (wintertijd) is geboekt.');

        $starts = Booking::query()->orderBy('starts_at')->get()->map(fn (Booking $b): string => rawTimes($b)['starts_at'])->all();
        $offered = offeredLabels($service, $employee, '2026-10-25');

        expect($starts)->toBe(['2026-10-25 00:30:00', '2026-10-25 01:30:00'])
            ->and($offered)->not->toContain('02:30 (zomertijd)')
            ->and($offered)->not->toContain('02:30 (wintertijd)')
            ->and($offered)->toContain('02:00 (wintertijd)');
    });

    it('blocks the repeated hour for an appointment running through the change', function () {
        [$employee, $service] = nightShiftEmployee();
        $service->update(['duration_minutes' => 90]);

        // 02:00 summer time + 90 min = 02:30 winter time on the clock.
        actingAs(User::factory()->customer()->create())->post(route('bookings.store'), [
            'service_id' => (string) $service->id,
            'employee_id' => (string) $employee->id,
            'starts_at' => '2026-10-25T02:00:00+02:00',
        ])->assertSessionHasNoErrors();

        // Left of 01:00-04:00 (4 real hours): 01:00-02:00 summer time and
        // 02:30 winter time to 04:00; a 90-minute appointment fits at 02:30.
        expect(rawTimes(Booking::sole())['ends_at'])->toBe('2026-10-25 01:30:00')
            ->and(offeredLabels($service, $employee, '2026-10-25'))->toBe(['02:30 (wintertijd)']);
    });

    it('shows the booking with the right suffix on the booking page and in the agenda', function () {
        [$employee, $service] = nightShiftEmployee();
        $customer = User::factory()->customer()->create();

        actingAs($customer)->post(route('bookings.store'), [
            'service_id' => (string) $service->id,
            'employee_id' => (string) $employee->id,
            'starts_at' => '2026-10-25T02:30:00+01:00',
        ])->assertSessionHasNoErrors();

        $booking = Booking::sole();

        actingAs($customer)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('25-10-2026 02:30 (wintertijd)')
            ->assertSee('- 03:00', false);

        actingAs($customer)->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('25-10-2026 02:30 (wintertijd)');

        actingAs($employee->user)->get(route('employees.agenda', [$employee, 'date' => '2026-10-25']))
            ->assertOk()
            ->assertSee('25-10-2026 02:30 (wintertijd)');
    });
});
