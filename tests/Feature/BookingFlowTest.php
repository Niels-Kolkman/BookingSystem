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
 * ISSUE-15: the booking page (service, employee, date, time) with free
 * times fetched live as JSON, and a clear message when a time was just
 * taken. "Now" is Monday 2026-11-23 10:00 in Amsterdam; the booking date
 * is Tuesday 2026-12-01 (UTC+1). The employee works Tuesdays 09:00-10:00;
 * the service takes 30 min + 15 min buffer, so 09:00, 09:15 and 09:30 are
 * offered.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['booking.slot_interval' => 15]);
});

/**
 * @return array{Employee, Service}
 */
function flowSetup(string $employeeName = 'Anna'): array
{
    $employee = Employee::factory()->create(['display_name' => $employeeName]);
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '10:00')->create();

    $service = Service::factory()->create(['name' => 'Knippen', 'duration_minutes' => 30, 'buffer_minutes' => 15, 'price_cents' => 2500]);
    $employee->services()->attach($service);

    return [$employee, $service];
}

function flowCustomer(): User
{
    return User::factory()->customer()->create();
}

/**
 * @return array<string, string>
 */
function flowQuery(Service $service, Employee $employee, string $date = '2026-12-01'): array
{
    return ['service_id' => (string) $service->id, 'employee_id' => (string) $employee->id, 'date' => $date];
}

/**
 * @return array<string, string>
 */
function flowInput(Service $service, Employee $employee, string $startsAt = '2026-12-01T09:15:00+01:00'): array
{
    return ['service_id' => (string) $service->id, 'employee_id' => (string) $employee->id, 'starts_at' => $startsAt];
}

function flowTakeSlot(Employee $employee, Service $service, string $localStart = '2026-12-01 09:15'): Booking
{
    $startsAt = CarbonImmutable::parse($localStart, 'Europe/Amsterdam')->utc();

    return Booking::factory()->create([
        'employee_id' => $employee->id,
        'service_id' => $service->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(30),
        'blocked_until' => $startsAt->addMinutes(45),
        'duration_minutes' => 30,
    ]);
}

describe('GET /bookings/slots', function () {
    it('returns the free times as booking input with a display label', function () {
        [$employee, $service] = flowSetup();

        actingAs(flowCustomer())
            ->getJson(route('bookings.slots', flowQuery($service, $employee)))
            ->assertOk()
            ->assertExactJson([
                'date' => '2026-12-01',
                'slots' => [
                    ['starts_at' => '2026-12-01T09:00:00+01:00', 'label' => '09:00'],
                    ['starts_at' => '2026-12-01T09:15:00+01:00', 'label' => '09:15'],
                    ['starts_at' => '2026-12-01T09:30:00+01:00', 'label' => '09:30'],
                ],
            ]);
    });

    it('leaves out a time that is taken, including the buffer', function () {
        [$employee, $service] = flowSetup();
        flowTakeSlot($employee, $service, '2026-12-01 09:00');

        $starts = actingAs(flowCustomer())
            ->getJson(route('bookings.slots', flowQuery($service, $employee)))
            ->assertOk()
            ->json('slots');

        // 09:00 + 30 min + 15 min buffer blocks until 09:45, the day ends at 10:00.
        expect($starts)->toBe([]);
    });

    it('gives no times on a day off, in the past or for an employee who does not offer the service', function () {
        [$employee, $service] = flowSetup();
        $other = Employee::factory()->create();
        WorkingHour::factory()->for($other)->on(Weekday::Tuesday, '09:00', '10:00')->create();
        $customer = flowCustomer();

        foreach ([
            flowQuery($service, $employee, '2026-12-02'), // Wednesday
            flowQuery($service, $employee, '2026-11-17'), // last Tuesday
            flowQuery($service, $other),
        ] as $query) {
            actingAs($customer)->getJson(route('bookings.slots', $query))
                ->assertOk()
                ->assertJsonPath('slots', []);
        }
    });

    it('rejects missing or malformed input with Dutch messages', function () {
        [$employee, $service] = flowSetup();

        actingAs(flowCustomer())
            ->getJson(route('bookings.slots', flowQuery($service, $employee, '2026-02-30')))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Kies een geldige datum.']);

        actingAs(flowCustomer())
            ->getJson(route('bookings.slots', ['date' => '2026-12-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id' => 'Kies een dienst.', 'employee_id' => 'Kies een medewerker.']);
    });

    it('is only for customers', function () {
        [$employee, $service] = flowSetup();
        $url = route('bookings.slots', flowQuery($service, $employee));

        get($url)->assertRedirect(route('login'));
        actingAs(User::factory()->employee()->create())->getJson($url)->assertForbidden();
        actingAs(User::factory()->admin()->create())->getJson($url)->assertForbidden();
    });
});

describe('GET /bookings/create', function () {
    it('starts with the bookable services only', function () {
        flowSetup();
        Service::factory()->create(['name' => 'Verven', 'is_active' => false]);
        Service::factory()->create(['name' => 'Zonder medewerker']);
        $inactiveEmployee = Employee::factory()->create(['is_active' => false]);
        $inactiveEmployee->services()->attach(Service::factory()->create(['name' => 'Alleen inactief']));

        actingAs(flowCustomer())->get(route('bookings.create'))
            ->assertOk()
            ->assertSee('1. Kies een dienst')
            ->assertSee('Knippen')
            ->assertSee('30 min · € 25,00')
            ->assertDontSee('Verven')
            ->assertDontSee('Zonder medewerker')
            ->assertDontSee('Alleen inactief')
            ->assertSee('data-booking-flow', false)
            ->assertSee(route('bookings.slots'), false);
    });

    it('embeds the active employees per service for the page script', function () {
        [$employee, $service] = flowSetup();
        Employee::factory()->create(['display_name' => 'Uit dienst', 'is_active' => false])->services()->attach($service);

        $html = (string) actingAs(flowCustomer())->get(route('bookings.create'))->assertOk()->getContent();

        preg_match('/<script type="application\/json" data-booking-catalogue>(.*?)<\/script>/s', $html, $match);
        expect(json_decode($match[1], true))->toBe([
            ['id' => $service->id, 'employees' => [['id' => $employee->id, 'name' => 'Anna']]],
        ]);
    });

    it('shows the next steps on the server, so it works without JavaScript', function () {
        [$employee, $service] = flowSetup();
        $customer = flowCustomer();

        actingAs($customer)->get(route('bookings.create', ['service_id' => $service->id]))
            ->assertOk()
            ->assertSee('2. Kies een medewerker')
            ->assertSee('Anna')
            ->assertDontSee('name="starts_at"', false);

        actingAs($customer)->get(route('bookings.create', flowQuery($service, $employee)))
            ->assertOk()
            ->assertSeeInOrder(['4. Kies een tijd', '09:00', '09:15', '09:30', 'Afspraak boeken'])
            ->assertSee('value="2026-12-01T09:15:00+01:00"', false);
    });

    it('says so when the day has no free times', function () {
        [$employee, $service] = flowSetup();

        actingAs(flowCustomer())->get(route('bookings.create', flowQuery($service, $employee, '2026-12-02')))
            ->assertOk()
            ->assertSee('Er zijn geen vrije tijden op deze dag. Kies een andere datum.');
    });

    it('ignores an employee who does not offer the chosen service and a malformed date', function () {
        [, $service] = flowSetup();
        $other = Employee::factory()->create(['display_name' => 'Bram']);

        actingAs(flowCustomer())->get(route('bookings.create', flowQuery($service, $other, 'morgen')))
            ->assertOk()
            ->assertDontSee('Bram')
            ->assertDontSee('name="starts_at"', false);
    });

    it('says so when nothing can be booked', function () {
        actingAs(flowCustomer())->get(route('bookings.create'))
            ->assertOk()
            ->assertSee('Er zijn op dit moment geen diensten die je online kunt boeken.');
    });

    it('is only for customers', function () {
        get(route('bookings.create'))->assertRedirect(route('login'));
        actingAs(User::factory()->employee()->create())->get(route('bookings.create'))->assertForbidden();
    });

    it('is linked from the navigation for customers only', function () {
        actingAs(flowCustomer())->get(route('dashboard'))->assertSee(route('bookings.create'), false);
        actingAs(User::factory()->employee()->create())->get(route('dashboard'))->assertDontSee(route('bookings.create'), false);
    });
});

describe('booking a time that was just taken', function () {
    it('answers the page script with 409 and the message when the employee was just booked', function () {
        [$employee, $service] = flowSetup();
        flowTakeSlot($employee, $service);

        actingAs(flowCustomer())
            ->postJson(route('bookings.store'), flowInput($service, $employee))
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'Dit tijdstip is helaas net bezet. Kies een ander tijdstip.',
                'reason' => 'employee_busy',
                'errors' => ['starts_at' => ['Dit tijdstip is helaas net bezet. Kies een ander tijdstip.']],
            ]);

        expect(Booking::count())->toBe(1);
    });

    it('answers 409 when the customer has another appointment then', function () {
        [$employee, $service] = flowSetup();
        $customer = flowCustomer();
        Booking::factory()->create([
            'customer_id' => $customer->id,
            'employee_id' => Employee::factory()->create()->id,
            'starts_at' => CarbonImmutable::parse('2026-12-01 08:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-12-01 09:00', 'UTC'),
            'blocked_until' => CarbonImmutable::parse('2026-12-01 09:00', 'UTC'),
        ]);

        actingAs($customer)->postJson(route('bookings.store'), flowInput($service, $employee))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'customer_busy');
    });

    it('answers 422 for a time that was never offered', function () {
        [$employee, $service] = flowSetup();

        actingAs(flowCustomer())
            ->postJson(route('bookings.store'), flowInput($service, $employee, '2026-12-01T09:10:00+01:00'))
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'not_offered')
            ->assertJsonValidationErrors(['starts_at' => 'Dit tijdstip is niet beschikbaar. Kies een van de aangeboden tijdstippen.']);
    });

    it('shows the message next to fresh free times without JavaScript', function () {
        [$employee, $service] = flowSetup();
        flowTakeSlot($employee, $service);
        $from = route('bookings.create', flowQuery($service, $employee));

        actingAs(flowCustomer())->from($from)
            ->post(route('bookings.store'), flowInput($service, $employee) + ['notes' => 'Graag kort'])
            ->assertRedirect($from);

        // 09:15 is taken and blocks until 10:00; 09:00 would run into it.
        get($from)
            ->assertOk()
            ->assertSee('Dit tijdstip is helaas net bezet. Kies een ander tijdstip.')
            ->assertSee('Er zijn geen vrije tijden op deze dag. Kies een andere datum.')
            ->assertSee('Graag kort');
    });

    it('restores the choice from the old input when the page was opened without a query', function () {
        [$employee, $service] = flowSetup();
        $customer = flowCustomer();
        Booking::factory()->create([
            'customer_id' => $customer->id,
            'employee_id' => Employee::factory()->create()->id,
            'starts_at' => CarbonImmutable::parse('2026-12-01 08:15', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-12-01 08:45', 'UTC'),
            'blocked_until' => CarbonImmutable::parse('2026-12-01 08:45', 'UTC'),
        ]);

        actingAs($customer)->from(route('bookings.create'))
            ->post(route('bookings.store'), flowInput($service, $employee))
            ->assertRedirect(route('bookings.create'));

        get(route('bookings.create'))
            ->assertOk()
            ->assertSee('Je hebt op dit tijdstip al een andere afspraak.')
            ->assertSee('value="2026-12-01"', false)
            ->assertSee('value="2026-12-01T09:15:00+01:00" checked', false);
    });
});

describe('booking from the page script', function () {
    it('answers 201 with the booking page and flashes the confirmation', function () {
        [$employee, $service] = flowSetup();

        $response = actingAs(flowCustomer())->postJson(route('bookings.store'), flowInput($service, $employee));

        $booking = Booking::sole();
        $response->assertCreated()
            ->assertExactJson(['redirect' => route('bookings.show', $booking)])
            ->assertSessionHas('status', 'Je afspraak op 01-12-2026 om 09:15 is geboekt.');
    });

    it('answers 422 for missing input', function () {
        actingAs(flowCustomer())->postJson(route('bookings.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id', 'employee_id', 'starts_at']);
    });
});
