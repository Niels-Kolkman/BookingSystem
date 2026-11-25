<?php

use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-15 edge cases on top of BookingFlowTest: the whole "just taken"
 * race, invalid ids and dates, inactive records, the night the clock goes
 * back and escaping of the embedded catalogue. Same setting as
 * BookingFlowTest: "now" is Monday 2026-11-23 10:00 in Amsterdam, the
 * employee works Tuesdays 09:00-10:00, the service takes 30 min + 15 min
 * buffer on a 15-minute grid.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['booking.slot_interval' => 15]);
});

/**
 * @return array{Employee, Service}
 */
function bfeSetup(array $employee = [], array $service = []): array
{
    $e = Employee::factory()->create($employee + ['display_name' => 'Anna']);
    WorkingHour::factory()->for($e)->on(Weekday::Tuesday, '09:00', '10:00')->create();

    $s = Service::factory()->create($service + ['name' => 'Knippen', 'duration_minutes' => 30, 'buffer_minutes' => 15]);
    $e->services()->attach($s);

    return [$e, $s];
}

/**
 * @return array<string, string>
 */
function bfeQuery(Service $service, Employee $employee, string $date = '2026-12-01'): array
{
    return ['service_id' => (string) $service->id, 'employee_id' => (string) $employee->id, 'date' => $date];
}

/**
 * @return list<string>
 */
function bfeStarts(User $customer, array $query): array
{
    return array_column(
        actingAs($customer)->getJson(route('bookings.slots', $query))->assertOk()->json('slots'),
        'starts_at',
    );
}

describe('the slot that is taken while the customer is choosing', function () {
    it('refuses the late customer with 409 and no longer offers the time afterwards', function () {
        [$employee, $service] = bfeSetup();
        $late = User::factory()->customer()->create();
        $quick = User::factory()->customer()->create();
        $input = ['service_id' => $service->id, 'employee_id' => $employee->id, 'starts_at' => '2026-12-01T09:15:00+01:00'];

        // The late customer sees 09:15 as free.
        expect(bfeStarts($late, bfeQuery($service, $employee)))->toContain('2026-12-01T09:15:00+01:00');

        actingAs($quick)->postJson(route('bookings.store'), $input)->assertCreated();

        actingAs($late)->postJson(route('bookings.store'), $input)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Dit tijdstip is helaas net bezet. Kies een ander tijdstip.');

        // The page script fetches the times again: 09:15 blocks 09:00-10:00.
        expect(bfeStarts($late, bfeQuery($service, $employee)))->toBe([])
            ->and(Booking::query()->pluck('customer_id')->all())->toBe([$quick->id]);
    });

    it('answers 422 with the message on employee_id when the employee can no longer do the service', function () {
        [$employee, $service] = bfeSetup();
        $employee->update(['is_active' => false]);

        actingAs(User::factory()->customer()->create())
            ->postJson(route('bookings.store'), ['service_id' => $service->id, 'employee_id' => $employee->id, 'starts_at' => '2026-12-01T09:15:00+01:00'])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'not_bookable')
            ->assertJsonValidationErrors(['employee_id' => 'Deze medewerker kan deze dienst niet uitvoeren. Kies een andere medewerker of dienst.']);

        expect(Booking::count())->toBe(0);
    });
});

describe('GET /bookings/slots edge cases', function () {
    it('rejects ids that do not exist', function () {
        actingAs(User::factory()->customer()->create())
            ->getJson(route('bookings.slots', ['service_id' => 999, 'employee_id' => 999, 'date' => '2026-12-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id' => 'Kies een dienst.', 'employee_id' => 'Kies een medewerker.']);
    });

    it('rejects a date that is not Y-m-d', function (string $date) {
        [$employee, $service] = bfeSetup();

        actingAs(User::factory()->customer()->create())
            ->getJson(route('bookings.slots', bfeQuery($service, $employee, $date)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Kies een geldige datum.']);
    })->with(['day first' => '01-12-2026', 'no leading zero' => '2026-12-1', 'with a time' => '2026-12-01 09:00', 'word' => 'morgen']);

    it('offers nothing for an inactive service or an inactive employee', function (array $employee, array $service) {
        [$e, $s] = bfeSetup($employee, $service);

        expect(bfeStarts(User::factory()->customer()->create(), bfeQuery($s, $e)))->toBe([]);
    })->with([
        'inactive service' => [[], ['is_active' => false]],
        'inactive employee' => [['is_active' => false], []],
    ]);

    it('offers both 02:30s of the night the clock goes back, and books the one picked', function () {
        // Sunday 2027-10-31: 03:00 summer time becomes 02:00 winter time.
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Sunday, '01:00', '04:00')->create();
        $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $employee->services()->attach($service);
        $customer = User::factory()->customer()->create();

        $slots = actingAs($customer)
            ->getJson(route('bookings.slots', bfeQuery($service, $employee, '2027-10-31')))
            ->assertOk()
            ->json('slots');

        expect($slots)->toContain(['starts_at' => '2027-10-31T02:30:00+02:00', 'label' => '02:30 (zomertijd)'])
            ->toContain(['starts_at' => '2027-10-31T02:30:00+01:00', 'label' => '02:30 (wintertijd)']);

        actingAs($customer)->postJson(route('bookings.store'), [
            'service_id' => $service->id,
            'employee_id' => $employee->id,
            'starts_at' => '2027-10-31T02:30:00+01:00',
        ])->assertCreated();

        expect(Booking::sole()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2027-10-31 01:30');
    });
});

describe('GET /bookings/create edge cases', function () {
    it('starts the date at today and does not allow earlier dates', function () {
        [, $service] = bfeSetup();

        actingAs(User::factory()->customer()->create())->get(route('bookings.create', ['service_id' => $service->id]))
            ->assertOk()
            ->assertSee('min="2026-11-23" value="2026-11-23"', false);
    });

    it('ignores ids that are not plain numbers and dates that do not exist', function (array $query) {
        [$employee, $service] = bfeSetup();
        $fill = fn ($v) => match ($v) {
            ':service' => (string) $service->id,
            ':employee' => (string) $employee->id,
            default => $v,
        };

        actingAs(User::factory()->customer()->create())->get(route('bookings.create', array_map($fill, $query)))
            ->assertOk()
            ->assertDontSee('name="starts_at"', false);
    })->with([
        'service id with letters' => [['service_id' => '1abc', 'employee_id' => ':employee', 'date' => '2026-12-01']],
        'negative employee id' => [['service_id' => ':service', 'employee_id' => '-1', 'date' => '2026-12-01']],
        'service id as array' => [['service_id' => ['1'], 'employee_id' => ':employee', 'date' => '2026-12-01']],
        '30 February' => [['service_id' => ':service', 'employee_id' => ':employee', 'date' => '2026-02-30']],
        'month 13' => [['service_id' => ':service', 'employee_id' => ':employee', 'date' => '2026-13-01']],
    ]);

    it('ignores an inactive service in the query even though it exists', function () {
        [$employee, $service] = bfeSetup(service: ['is_active' => false]);

        actingAs(User::factory()->customer()->create())->get(route('bookings.create', bfeQuery($service, $employee)))
            ->assertOk()
            ->assertDontSee('Knippen')
            ->assertDontSee('name="starts_at"', false);
    });

    it('prefers the query string over the old input of a refused booking', function () {
        [$employee, $service] = bfeSetup();
        Booking::factory()->create([
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => CarbonImmutable::parse('2026-12-01 08:15', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-12-01 08:45', 'UTC'),
            'blocked_until' => CarbonImmutable::parse('2026-12-01 09:00', 'UTC'),
        ]);

        actingAs(User::factory()->customer()->create())->from(route('bookings.create'))
            ->post(route('bookings.store'), ['service_id' => $service->id, 'employee_id' => $employee->id, 'starts_at' => '2026-12-01T09:15:00+01:00'])
            ->assertSessionHasErrors('starts_at');

        // The customer picks another Tuesday; the old 1 December must not win.
        get(route('bookings.create', bfeQuery($service, $employee, '2026-12-08')))
            ->assertOk()
            ->assertSee('value="2026-12-08"', false)
            ->assertSee('value="2026-12-08T09:15:00+01:00"', false)
            ->assertDontSee('2026-12-01T', false);
    });

    it('does not let an employee name break out of the embedded catalogue', function () {
        bfeSetup(['display_name' => '</script><script>alert(1)</script>']);

        $html = (string) actingAs(User::factory()->customer()->create())->get(route('bookings.create'))->assertOk()->getContent();

        preg_match('/<script type="application\/json" data-booking-catalogue>(.*?)<\/script>/s', $html, $match);
        expect($html)->not->toContain('<script>alert(1)')
            ->and(json_decode($match[1], true)[0]['employees'][0]['name'])->toBe('</script><script>alert(1)</script>');
    });
});
