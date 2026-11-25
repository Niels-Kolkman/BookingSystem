<?php

use App\Bookings\BookingRefused;
use App\Bookings\CreateBooking;
use App\Bookings\RescheduleBooking;
use App\Bookings\RescheduleRefused;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Scheduling\Slot;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-17 edge cases for moving a booking, on top of
 * RescheduleBookingTest. Same agenda: "now" is Monday 2026-11-23 09:00 in
 * Amsterdam; the employee works Monday 08:00-12:00 and Tuesday and
 * Wednesday 09:00-12:00; 30 minutes plus a 15-minute buffer, every 15
 * minutes. The booking is Tuesday 2026-11-24 10:00.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 09:00', 'Europe/Amsterdam'));
    config(['booking.cancellation_window_hours' => 24, 'booking.slot_interval' => 15]);
});

function rseLocal(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse($dateTime, 'Europe/Amsterdam');
}

/**
 * @return array{Employee, Service}
 */
function rseAgenda(): array
{
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Monday, '08:00', '12:00')->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Wednesday, '09:00', '12:00')->create();
    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
    $employee->services()->attach($service);

    return [$employee, $service];
}

function rseBook(Employee $employee, Service $service, string $start = '2026-11-24 10:00', ?User $customer = null): Booking
{
    return app(CreateBooking::class)->handle($customer ?? User::factory()->customer()->create(), $service, $employee, rseLocal($start));
}

function rseMove(User $by, Booking $booking, string|CarbonImmutable $start): BookingRefused|RescheduleRefused|null
{
    try {
        app(RescheduleBooking::class)->handle($by, $booking, is_string($start) ? rseLocal($start) : $start);
    } catch (BookingRefused|RescheduleRefused $refused) {
        return $refused;
    }

    return null;
}

function rseReason(BookingRefused|RescheduleRefused|null $refused): ?string
{
    return $refused instanceof BookingRefused ? $refused->reason : null;
}

function rseStartsAt(Booking $booking, string $start): bool
{
    return (bool) $booking->fresh()?->starts_at->equalTo(rseLocal($start));
}

function rseCancel(Booking $booking): void
{
    $booking->status = BookingStatus::Cancelled;
    $booking->save();
}

/**
 * @param  list<Slot>  $slots
 * @return list<string>
 */
function rseLabels(array $slots): array
{
    return array_map(fn (Slot $slot): string => $slot->label(), $slots);
}

describe('what blocks the new time', function () {
    it('is not blocked by a cancelled booking of the employee', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        rseCancel(rseBook($employee, $service, '2026-11-24 11:00'));

        expect(rseMove($booking->customer, $booking, '2026-11-24 11:00'))->toBeNull()
            ->and(rseStartsAt($booking, '2026-11-24 11:00'))->toBeTrue();
    });

    it('is not blocked by a cancelled appointment of the customer elsewhere', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        [$otherEmployee, $otherService] = rseAgenda();
        /** @var User $customer */
        $customer = $booking->customer;
        rseCancel(rseBook($otherEmployee, $otherService, '2026-11-24 11:00', $customer));

        expect(rseMove($customer, $booking, '2026-11-24 11:00'))->toBeNull();
    });

    it('is not blocked by a booking of another employee', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        [$otherEmployee, $otherService] = rseAgenda();
        rseBook($otherEmployee, $otherService, '2026-11-24 11:00');

        expect(rseMove($booking->customer, $booking, '2026-11-24 11:00'))->toBeNull();
    });

    it('is blocked by the buffer of an earlier booking of the employee', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        // 09:00-09:30, blocked until 09:45.
        rseBook($employee, $service, '2026-11-24 09:00');

        expect(rseReason(rseMove($booking->customer, $booking, '2026-11-24 09:30')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(rseStartsAt($booking, '2026-11-24 10:00'))->toBeTrue()
            // Starting right when that buffer ends is fine.
            ->and(rseMove($booking->customer, $booking, '2026-11-24 09:45'))->toBeNull();
    });

    it('does not count the buffer of the customer\'s other appointment', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        [$otherEmployee, $otherService] = rseAgenda();
        /** @var User $customer */
        $customer = $booking->customer;
        // The customer is busy 11:00-11:30; the 15-minute buffer is the other employee's time.
        rseBook($otherEmployee, $otherService, '2026-11-24 11:00', $customer);

        expect(rseReason(rseMove($customer, $booking, '2026-11-24 11:15')))->toBe(BookingRefused::REASON_CUSTOMER_BUSY)
            ->and(rseMove($customer, $booking, '2026-11-24 11:30'))->toBeNull();
    });

    it('refuses when the service is no longer active, and keeps the old time', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        $service->is_active = false;
        $service->save();

        expect(rseReason(rseMove($booking->customer, $booking, '2026-11-24 11:00')))->toBe(BookingRefused::REASON_NOT_BOOKABLE)
            ->and(rseStartsAt($booking, '2026-11-24 10:00'))->toBeTrue();
    });

    it('refuses when the employee is no longer active, and keeps the old time', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        $employee->is_active = false;
        $employee->save();

        expect(rseReason(rseMove($booking->customer, $booking, '2026-11-24 11:00')))->toBe(BookingRefused::REASON_NOT_BOOKABLE)
            ->and(rseStartsAt($booking, '2026-11-24 10:00'))->toBeTrue();
    });

    it('refuses an inactive service before looking at the new time', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        $service->is_active = false;
        $service->save();

        // The order in RescheduleBooking: not bookable comes before "same slot".
        expect(rseReason(rseMove($booking->customer, $booking, '2026-11-24 10:00')))->toBe(BookingRefused::REASON_NOT_BOOKABLE);
    });
});

describe('freeing the old time', function () {
    it('lets another customer book the old time once the move succeeded', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);

        expect(rseMove($booking->customer, $booking, '2026-11-24 11:00'))->toBeNull();
        $newcomer = rseBook($employee, $service, '2026-11-24 10:00');

        expect($newcomer->exists)->toBeTrue()
            ->and(Booking::query()->count())->toBe(2);
    });

    it('lets two bookings trade places one after the other', function () {
        [$employee, $service] = rseAgenda();
        $first = rseBook($employee, $service);
        $second = rseBook($employee, $service, '2026-11-24 11:00');

        // 11:00 is still taken by the second booking.
        expect(rseReason(rseMove($first->customer, $first, '2026-11-24 11:00')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(rseMove($second->customer, $second, '2026-11-24 09:00'))->toBeNull()
            ->and(rseMove($first->customer, $first, '2026-11-24 11:00'))->toBeNull()
            ->and(rseMove($second->customer, $second, '2026-11-24 10:00'))->toBeNull()
            ->and(rseStartsAt($first, '2026-11-24 11:00'))->toBeTrue()
            ->and(rseStartsAt($second, '2026-11-24 10:00'))->toBeTrue();
    });
});

describe('times', function () {
    it('takes the new start in any time zone', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);

        // 10:00 UTC is 11:00 in Amsterdam.
        expect(rseMove($booking->customer, $booking, CarbonImmutable::parse('2026-11-24 10:00', 'UTC')))->toBeNull()
            ->and(rseStartsAt($booking, '2026-11-24 11:00'))->toBeTrue();
    });

    it('uses the current buffer of the service for the new time', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        $service->buffer_minutes = 0;
        $service->save();

        expect(rseMove($booking->customer, $booking, '2026-11-24 11:00'))->toBeNull()
            ->and($booking->fresh()?->blocked_until->equalTo(rseLocal('2026-11-24 11:30')))->toBeTrue();
    });

    it('keeps the local time when moving across the start of summer time', function () {
        // Summer time starts on Sunday 2027-03-28. Monday 2027-03-29 is
        // Easter Monday, a holiday, so the move goes to Tuesday.
        travelTo(rseLocal('2027-03-25 09:00'));
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Friday, '09:00', '12:00')->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();
        $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
        $employee->services()->attach($service);
        $booking = rseBook($employee, $service, '2027-03-26 10:00');
        expect($booking->starts_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-26 09:00');

        expect(rseMove($booking->customer, $booking, '2027-03-30 10:00'))->toBeNull();

        $booking->refresh();
        expect($booking->starts_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-30 08:00')
            ->and($booking->ends_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-30 08:30')
            ->and($booking->blocked_until->utc()->format('Y-m-d H:i'))->toBe('2027-03-30 08:45');
    });
});

describe('slots offered for moving', function () {
    it('leaves out times blocked by other bookings of the employee, buffer included', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        // 11:00-11:30, blocked until 11:45.
        rseBook($employee, $service, '2026-11-24 11:00');

        $labels = rseLabels(app(RescheduleBooking::class)->slotsFor($booking, rseLocal('2026-11-24 00:00')));

        expect($labels)->toContain('10:15')
            ->and($labels)->not->toContain('10:00', '10:30', '10:45', '11:00', '11:15');
    });

    it('offers the time of a cancelled booking and ignores other employees', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        rseCancel(rseBook($employee, $service, '2026-11-24 11:00'));
        [$otherEmployee, $otherService] = rseAgenda();
        rseBook($otherEmployee, $otherService, '2026-11-24 09:00');

        $labels = rseLabels(app(RescheduleBooking::class)->slotsFor($booking, rseLocal('2026-11-24 00:00')));

        expect($labels)->toContain('09:00', '11:00');
    });

    it('offers nothing on a day off', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);

        expect(app(RescheduleBooking::class)->slotsFor($booking, rseLocal('2026-11-26 00:00')))->toBe([]);
    });

    it('offers only times that the move accepts', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        rseBook($employee, $service, '2026-11-24 11:00');
        $admin = User::factory()->admin()->create();

        $slots = app(RescheduleBooking::class)->slotsFor($booking, rseLocal('2026-11-24 00:00'));
        expect($slots)->not->toBe([]);

        foreach ($slots as $slot) {
            expect(rseMove($admin, $booking, $slot->startsAt))->toBeNull();
            // Back to 10:00 for the next slot.
            expect(rseMove($admin, $booking, '2026-11-24 10:00'))->toBeNull();
        }
    });
});

describe('pages', function () {
    it('falls back to the date of the booking for a date that is not real', function (string $date) {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);

        actingAs($booking->customer)->get(route('bookings.reschedule', [$booking, 'date' => $date]))
            ->assertOk()
            ->assertViewHas('date', '2026-11-24');
    })->with([
        'not a date' => ['morgen'],
        'no such day' => ['2026-02-30'],
        'other format' => ['24-11-2026'],
    ]);

    it('shows the date of the refused time again after a refusal', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        rseBook($employee, $service, '2026-11-25 09:00');

        actingAs($booking->customer)
            ->post(route('bookings.reschedule.store', $booking), ['starts_at' => '2026-11-25T09:00:00+01:00'])
            ->assertRedirect(route('bookings.reschedule', $booking));

        actingAs($booking->customer)->get(route('bookings.reschedule', $booking))
            ->assertOk()
            ->assertViewHas('date', '2026-11-25');
    });

    it('shows the same-slot refusal next to the time', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);

        actingAs($booking->customer)
            ->post(route('bookings.reschedule.store', $booking), ['starts_at' => '2026-11-24T10:00:00+01:00'])
            ->assertRedirect(route('bookings.reschedule', $booking))
            ->assertSessionHasErrors(['starts_at' => 'De afspraak staat al op dit tijdstip. Kies een ander tijdstip.']);
    });

    it('confirms the new time in Amsterdam time when posted with another offset', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);

        actingAs($booking->customer)
            ->post(route('bookings.reschedule.store', $booking), ['starts_at' => '2026-11-24T10:00:00+00:00'])
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('status', 'De afspraak is verplaatst naar 24-11-2026 om 11:00.');
    });

    it('sends the employee back to the booking with the reason', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);

        actingAs($employee->user)->get(route('bookings.reschedule', $booking))
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('error', 'Alleen de klant of de beheerder kan deze afspraak verplaatsen.');

        actingAs($employee->user)
            ->post(route('bookings.reschedule.store', $booking), ['starts_at' => '2026-11-24T11:00:00+01:00'])
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('error', 'Alleen de klant of de beheerder kan deze afspraak verplaatsen.');

        expect(rseStartsAt($booking, '2026-11-24 10:00'))->toBeTrue();
    });

    it('sends back from the page of a cancelled booking', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        rseCancel($booking);

        actingAs($booking->customer)->get(route('bookings.reschedule', $booking))
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('error', 'Deze afspraak is geannuleerd en kan niet meer worden verplaatst.');
    });

    it('lets an admin move after the customer deadline, worded for the customer being busy', function () {
        [$employee, $service] = rseAgenda();
        $booking = rseBook($employee, $service);
        [$otherEmployee, $otherService] = rseAgenda();
        rseBook($otherEmployee, $otherService, '2026-11-24 11:00', $booking->customer);
        travelTo(rseLocal('2026-11-24 08:00'));
        $admin = User::factory()->admin()->create();

        actingAs($admin)->get(route('bookings.reschedule', $booking))->assertOk();

        actingAs($admin)
            ->post(route('bookings.reschedule.store', $booking), ['starts_at' => '2026-11-24T11:00:00+01:00'])
            ->assertSessionHasErrors(['starts_at' => 'De klant heeft op dit tijdstip al een andere afspraak.']);

        actingAs($admin)
            ->post(route('bookings.reschedule.store', $booking), ['starts_at' => '2026-11-25T09:00:00+01:00'])
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('status', 'De afspraak is verplaatst naar 25-11-2026 om 09:00.');
    });

    it('answers 404 for a booking that does not exist', function () {
        $customer = User::factory()->customer()->create();

        actingAs($customer)->get(route('bookings.reschedule', 999999))->assertNotFound();
        actingAs($customer)->post(route('bookings.reschedule.store', 999999), ['starts_at' => '2026-11-24T11:00:00+01:00'])->assertNotFound();
    });
});
