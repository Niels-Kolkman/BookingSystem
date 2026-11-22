<?php

use App\Bookings\BookingRefused;
use App\Bookings\CreateBooking;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-12: a customer books a free slot; overlap with the employee's
 * bookings (buffer included) and with the customer's own appointments
 * (buffer excluded) is refused. "Now" is Monday 2026-11-23 10:00 in
 * Amsterdam; the booking date is Tuesday 2026-12-01 (UTC+1). The employee
 * works Tuesdays 09:00-12:00; the service takes 30 min + 15 min buffer.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['booking.slot_interval' => 15]);
});

/**
 * @return array{Employee, Service}
 */
function bookableEmployee(): array
{
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();

    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15, 'price_cents' => 2500]);
    $employee->services()->attach($service);

    return [$employee, $service];
}

function tuesdayAt(string $time, string $date = '2026-12-01'): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} {$time}", 'Europe/Amsterdam');
}

/**
 * An existing appointment, given in local time.
 *
 * @param  array<string, mixed>  $attributes
 */
function existingBooking(Employee $employee, string $start, int $duration = 30, int $buffer = 0, array $attributes = []): Booking
{
    $startsAt = tuesdayAt($start)->utc();

    return Booking::factory()->create([
        'employee_id' => $employee->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes($duration),
        'blocked_until' => $startsAt->addMinutes($duration + $buffer),
        'duration_minutes' => $duration,
        ...$attributes,
    ]);
}

function book(User $customer, Service $service, Employee $employee, CarbonImmutable $startsAt): Booking
{
    return app(CreateBooking::class)->handle($customer, $service->fresh() ?? $service, $employee->fresh() ?? $employee, $startsAt);
}

/**
 * The BookingRefused reason, or null when the attempt succeeded.
 */
function refusalOf(callable $attempt): ?string
{
    try {
        $attempt();
    } catch (BookingRefused $refused) {
        return $refused->reason;
    }

    return null;
}

/**
 * @return array<string, string>
 */
function bookingInput(Service $service, Employee $employee, string $startsAt = '2026-12-01T09:15:00+01:00'): array
{
    return [
        'service_id' => (string) $service->id,
        'employee_id' => (string) $employee->id,
        'starts_at' => $startsAt,
    ];
}

describe('CreateBooking', function () {
    it('books a free slot with UTC times and a snapshot of the service', function () {
        [$employee, $service] = bookableEmployee();
        $customer = User::factory()->customer()->create();

        $booking = app(CreateBooking::class)->handle($customer, $service, $employee, tuesdayAt('09:15'), '  Graag kort  ');

        $booking->refresh();
        expect($booking->customer_id)->toBe($customer->id)
            ->and($booking->employee_id)->toBe($employee->id)
            ->and($booking->service_id)->toBe($service->id)
            ->and($booking->starts_at->toDateTimeString())->toBe('2026-12-01 08:15:00')
            ->and($booking->ends_at->toDateTimeString())->toBe('2026-12-01 08:45:00')
            ->and($booking->blocked_until->toDateTimeString())->toBe('2026-12-01 09:00:00')
            ->and($booking->duration_minutes)->toBe(30)
            ->and($booking->price_cents)->toBe(2500)
            ->and($booking->status)->toBe(BookingStatus::Requested)
            ->and($booking->notes)->toBe('Graag kort');
    });

    it('refuses a start that overlaps another booking of the same employee', function () {
        [$employee, $service] = bookableEmployee();
        existingBooking($employee, '10:00', 30);
        $customer = User::factory()->customer()->create();

        // 09:45 + 30 min + 15 min buffer runs into 10:00; 10:15 starts inside it.
        expect(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('09:45'))))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('10:00'))))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('10:15'))))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(Booking::count())->toBe(1);
    });

    it('refuses a start inside the buffer of an existing booking of the employee', function () {
        [$employee, $service] = bookableEmployee();
        existingBooking($employee, '10:00', 30, buffer: 15);
        $customer = User::factory()->customer()->create();

        expect(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('10:30'))))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY);
    });

    it('allows bookings that touch the blocked time of a neighbour', function () {
        [$employee, $service] = bookableEmployee();
        existingBooking($employee, '10:00', 30, buffer: 15);

        // 09:15 blocks until 10:00; 10:45 starts when the existing buffer ends.
        book(User::factory()->customer()->create(), $service, $employee, tuesdayAt('09:15'));
        book(User::factory()->customer()->create(), $service, $employee, tuesdayAt('10:45'));

        expect(Booking::count())->toBe(3);
    });

    it('refuses the same slot with the same employee for a second customer', function () {
        [$employee, $service] = bookableEmployee();
        book(User::factory()->customer()->create(), $service, $employee, tuesdayAt('09:00'));

        $other = User::factory()->customer()->create();

        expect(refusalOf(fn () => book($other, $service, $employee, tuesdayAt('09:00'))))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(Booking::count())->toBe(1);
    });

    it('refuses when the customer already has an appointment with another employee at that time', function () {
        [$employee, $service] = bookableEmployee();
        [$otherEmployee] = bookableEmployee();
        $customer = User::factory()->customer()->create();
        existingBooking($otherEmployee, '09:45', 30, attributes: ['customer_id' => $customer->id]);

        // 09:30-10:00 and 10:00-10:30 overlap the customer's 09:45-10:15; the employee is free.
        expect(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('09:30'))))->toBe(BookingRefused::REASON_CUSTOMER_BUSY)
            ->and(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('10:00'))))->toBe(BookingRefused::REASON_CUSTOMER_BUSY)
            ->and(Booking::count())->toBe(1);
    });

    it('refuses a second appointment of the customer at the same time with the same employee', function () {
        [$employee, $service] = bookableEmployee();
        $customer = User::factory()->customer()->create();
        book($customer, $service, $employee, tuesdayAt('09:00'));

        // The employee check comes first, so the reason is the employee's.
        expect(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('09:00'))))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(Booking::count())->toBe(1);
    });

    it('ignores the buffer for the customer: a new appointment may start when the previous one ends', function () {
        [$employee, $service] = bookableEmployee();
        [$otherEmployee] = bookableEmployee();
        $customer = User::factory()->customer()->create();
        // 09:00-09:30, the other employee is blocked until 09:45.
        existingBooking($otherEmployee, '09:00', 30, buffer: 15, attributes: ['customer_id' => $customer->id]);

        expect(book($customer, $service, $employee, tuesdayAt('09:30'))->exists)->toBeTrue();
    });

    it('does not count appointments of other customers with other employees', function () {
        [$employee, $service] = bookableEmployee();
        [$otherEmployee] = bookableEmployee();
        existingBooking($otherEmployee, '09:00', 30);

        expect(book(User::factory()->customer()->create(), $service, $employee, tuesdayAt('09:00'))->exists)->toBeTrue();
    });

    it('ignores cancelled, completed and no-show bookings', function (BookingStatus $status) {
        [$employee, $service] = bookableEmployee();
        $customer = User::factory()->customer()->create();
        existingBooking($employee, '09:00', 30, attributes: ['status' => $status, 'customer_id' => $customer->id]);

        expect(book($customer, $service, $employee, tuesdayAt('09:00'))->exists)->toBeTrue();
    })->with([
        'cancelled' => [BookingStatus::Cancelled],
        'completed' => [BookingStatus::Completed],
        'no-show' => [BookingStatus::NoShow],
    ]);

    it('counts requested bookings as taken, for the employee and for the customer', function () {
        [$employee, $service] = bookableEmployee();
        [$otherEmployee] = bookableEmployee();
        $customer = User::factory()->customer()->create();
        existingBooking($employee, '09:00', 30, attributes: ['status' => BookingStatus::Requested]);
        existingBooking($otherEmployee, '11:00', 30, attributes: ['status' => BookingStatus::Requested, 'customer_id' => $customer->id]);

        expect(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('09:00'))))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('11:00'))))->toBe(BookingRefused::REASON_CUSTOMER_BUSY);
    });

    it('refuses a start that is not an offered slot', function (string $date, string $time) {
        [$employee, $service] = bookableEmployee();
        $customer = User::factory()->customer()->create();

        expect(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt($time, $date))))->toBe(BookingRefused::REASON_NOT_OFFERED)
            ->and(Booking::count())->toBe(0);
    })->with([
        'before working hours' => ['2026-12-01', '08:45'],
        'does not fit before the end' => ['2026-12-01', '11:45'],
        'off the interval grid' => ['2026-12-01', '09:10'],
        'not a working day' => ['2026-12-02', '09:00'],
        'in the past' => ['2026-11-17', '09:00'],
    ]);

    it('refuses an unlinked employee, an inactive service and an inactive employee', function () {
        [$employee, $service] = bookableEmployee();
        $customer = User::factory()->customer()->create();

        $unlinked = Employee::factory()->create();
        WorkingHour::factory()->for($unlinked)->on(Weekday::Tuesday, '09:00', '12:00')->create();

        expect(refusalOf(fn () => book($customer, $service, $unlinked, tuesdayAt('09:00'))))->toBe(BookingRefused::REASON_NOT_BOOKABLE);

        $service->update(['is_active' => false]);
        expect(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('09:00'))))->toBe(BookingRefused::REASON_NOT_BOOKABLE);

        $service->update(['is_active' => true]);
        $employee->update(['is_active' => false]);
        expect(refusalOf(fn () => book($customer, $service, $employee, tuesdayAt('09:00'))))->toBe(BookingRefused::REASON_NOT_BOOKABLE)
            ->and(Booking::count())->toBe(0);
    });
});

describe('POST /bookings', function () {
    it('lets a customer book a free slot and shows the booking', function () {
        [$employee, $service] = bookableEmployee();
        $customer = User::factory()->customer()->create();

        $response = actingAs($customer)->post(route('bookings.store'), bookingInput($service, $employee) + ['notes' => 'Eerste keer']);

        $booking = Booking::sole();
        $response->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('status', 'Je afspraak op 01-12-2026 om 09:15 is geboekt.');
        expect($booking->customer_id)->toBe($customer->id)
            ->and($booking->starts_at->toDateTimeString())->toBe('2026-12-01 08:15:00')
            ->and($booking->notes)->toBe('Eerste keer');
    });

    it('accepts the start with a UTC offset as well', function () {
        [$employee, $service] = bookableEmployee();

        actingAs(User::factory()->customer()->create())
            ->post(route('bookings.store'), bookingInput($service, $employee, '2026-12-01T08:15:00+00:00'))
            ->assertSessionHasNoErrors();

        expect(Booking::sole()->starts_at->toDateTimeString())->toBe('2026-12-01 08:15:00');
    });

    it('goes back with a message when the employee is already booked', function () {
        [$employee, $service] = bookableEmployee();
        existingBooking($employee, '09:15', 30);

        actingAs(User::factory()->customer()->create())
            ->from(route('bookings.index'))
            ->post(route('bookings.store'), bookingInput($service, $employee))
            ->assertRedirect(route('bookings.index'))
            ->assertSessionHasErrors(['starts_at' => 'Dit tijdstip is helaas net bezet. Kies een ander tijdstip.'])
            ->assertSessionHasInput('starts_at');

        expect(Booking::count())->toBe(1);
    });

    it('goes back with a message when the customer already has an appointment then', function () {
        [$employee, $service] = bookableEmployee();
        [$otherEmployee] = bookableEmployee();
        $customer = User::factory()->customer()->create();
        existingBooking($otherEmployee, '09:15', 30, attributes: ['customer_id' => $customer->id]);

        actingAs($customer)
            ->post(route('bookings.store'), bookingInput($service, $employee))
            ->assertSessionHasErrors(['starts_at' => 'Je hebt op dit tijdstip al een andere afspraak.']);

        expect(Booking::count())->toBe(1);
    });

    it('puts a not bookable employee on the employee field', function () {
        [, $service] = bookableEmployee();
        $unlinked = Employee::factory()->create();

        actingAs(User::factory()->customer()->create())
            ->post(route('bookings.store'), bookingInput($service, $unlinked))
            ->assertSessionHasErrors('employee_id');
    });

    it('validates the input', function (array $override, string $field) {
        [$employee, $service] = bookableEmployee();

        actingAs(User::factory()->customer()->create())
            ->post(route('bookings.store'), array_merge(bookingInput($service, $employee), $override))
            ->assertSessionHasErrors($field);

        expect(Booking::count())->toBe(0);
    })->with([
        'missing service' => [['service_id' => ''], 'service_id'],
        'unknown service' => [['service_id' => '999999'], 'service_id'],
        'missing employee' => [['employee_id' => ''], 'employee_id'],
        'unknown employee' => [['employee_id' => '999999'], 'employee_id'],
        'missing start' => [['starts_at' => ''], 'starts_at'],
        'start without offset' => [['starts_at' => '2026-12-01 09:15'], 'starts_at'],
        'start with Z instead of an offset' => [['starts_at' => '2026-12-01T08:15:00Z'], 'starts_at'],
        'start is not a date' => [['starts_at' => 'morgen'], 'starts_at'],
        'notes too long' => [['notes' => str_repeat('a', 1001)], 'notes'],
    ]);

    it('lets only customers book', function (string $role) {
        [$employee, $service] = bookableEmployee();

        actingAs(User::factory()->{$role}()->create())
            ->post(route('bookings.store'), bookingInput($service, $employee))
            ->assertForbidden();

        expect(Booking::count())->toBe(0);
    })->with(['employee', 'admin']);

    it('sends guests to the login page', function () {
        [$employee, $service] = bookableEmployee();

        post(route('bookings.store'), bookingInput($service, $employee))->assertRedirect(route('login'));

        expect(Booking::count())->toBe(0);
    });
});
