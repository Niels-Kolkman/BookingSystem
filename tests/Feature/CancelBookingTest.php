<?php

use App\Bookings\CancelBooking;
use App\Bookings\CancellationRefused;
use App\Bookings\CreateBooking;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Policies\BookingPolicy;
use App\Scheduling\AvailableSlots;
use App\Scheduling\Slot;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-16: a customer cancels until the cancellation window (default 24
 * hours) before the start; after that only an admin can. A cancelled
 * booking frees its slot. "Now" is Monday 2026-11-23 09:00 in Amsterdam;
 * the booking is Tuesday 2026-11-24 10:00 (UTC+1), so with 24 hours the
 * deadline is Monday 10:00.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 09:00', 'Europe/Amsterdam'));
    config(['booking.cancellation_window_hours' => 24, 'booking.slot_interval' => 15]);
});

function cancelLocal(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse($dateTime, 'Europe/Amsterdam');
}

/**
 * A booking of a fresh customer, starting at a local time.
 *
 * @param  array<string, mixed>  $attributes
 */
function cancelBookingAt(string $start = '2026-11-24 10:00', array $attributes = []): Booking
{
    $startsAt = cancelLocal($start)->utc();

    return Booking::factory()->create([
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(30),
        'blocked_until' => $startsAt->addMinutes(30),
        'status' => BookingStatus::Confirmed,
        ...$attributes,
    ]);
}

/**
 * The refusal message, or null when the cancellation went through.
 */
function cancelRefusal(User $by, Booking $booking): ?string
{
    try {
        app(CancelBooking::class)->handle($by, $booking);
    } catch (CancellationRefused $refused) {
        return $refused->getMessage();
    }

    return null;
}

describe('customer', function () {
    it('cancels their own booking before the deadline', function () {
        $booking = cancelBookingAt();

        actingAs($booking->customer)
            ->post(route('bookings.cancel', $booking), ['cancellation_reason' => '  Ziek  '])
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('status', 'De afspraak op 24-11-2026 om 10:00 is geannuleerd.');

        $booking->refresh();
        expect($booking->status)->toBe(BookingStatus::Cancelled)
            ->and($booking->cancelled_at?->equalTo(CarbonImmutable::now()))->toBeTrue()
            ->and($booking->cancelled_by)->toBe($booking->customer_id)
            ->and($booking->cancellation_reason)->toBe('Ziek');
    });

    it('can still cancel exactly at the deadline', function () {
        $booking = cancelBookingAt();
        travelTo(cancelLocal('2026-11-23 10:00'));

        expect(cancelRefusal($booking->customer, $booking))->toBeNull()
            ->and($booking->fresh()?->status)->toBe(BookingStatus::Cancelled);
    });

    it('cannot cancel one second after the deadline', function () {
        $booking = cancelBookingAt();
        travelTo(cancelLocal('2026-11-23 10:00:01'));

        actingAs($booking->customer)
            ->post(route('bookings.cancel', $booking))
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('error', 'Online annuleren kan tot 24 uur van tevoren. Neem contact met ons op om deze afspraak te annuleren.');

        $booking->refresh();
        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->cancelled_at)->toBeNull()
            ->and($booking->cancelled_by)->toBeNull();
    });

    it('cancels a requested booking as well', function () {
        $booking = cancelBookingAt(attributes: ['status' => BookingStatus::Requested]);

        expect(cancelRefusal($booking->customer, $booking))->toBeNull();
    });

    it('gets 404 for the booking of another customer', function () {
        $booking = cancelBookingAt();

        actingAs(User::factory()->customer()->create())
            ->post(route('bookings.cancel', $booking))
            ->assertNotFound();

        expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    });

    it('is refused as not found by the action too', function () {
        $booking = cancelBookingAt();

        try {
            app(CancelBooking::class)->handle(User::factory()->customer()->create(), $booking);
            $this->fail('Expected a refusal.');
        } catch (CancellationRefused $refused) {
            expect($refused->notFound)->toBeTrue();
        }
    });
});

describe('admin', function () {
    it('cancels after the customer deadline, with a reason', function () {
        $booking = cancelBookingAt();
        travelTo(cancelLocal('2026-11-24 09:30'));
        $admin = User::factory()->admin()->create();

        actingAs($admin)
            ->post(route('bookings.cancel', $booking), ['cancellation_reason' => 'Medewerker ziek'])
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('status');

        $booking->refresh();
        expect($booking->status)->toBe(BookingStatus::Cancelled)
            ->and($booking->cancelled_by)->toBe($admin->id)
            ->and($booking->cancellation_reason)->toBe('Medewerker ziek');
    });

    it('cannot cancel a booking twice', function () {
        $booking = cancelBookingAt(attributes: ['status' => BookingStatus::Cancelled]);

        expect(cancelRefusal(User::factory()->admin()->create(), $booking))->toBe('Deze afspraak is al geannuleerd.');
    });

    it('cannot cancel a completed or no-show booking', function (BookingStatus $status) {
        $booking = cancelBookingAt(attributes: ['status' => $status]);

        expect(cancelRefusal(User::factory()->admin()->create(), $booking))->toBe('Deze afspraak kan niet meer worden geannuleerd.')
            ->and($booking->fresh()?->status)->toBe($status);
    })->with([
        'completed' => [BookingStatus::Completed],
        'no-show' => [BookingStatus::NoShow],
    ]);
});

describe('employee', function () {
    it('cannot cancel a booking in their own agenda', function () {
        $booking = cancelBookingAt();
        /** @var Employee $employee */
        $employee = $booking->employee;

        actingAs($employee->user)
            ->post(route('bookings.cancel', $booking))
            ->assertSessionHas('error', 'Alleen de klant of de beheerder kan deze afspraak annuleren.');

        expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    });

    it('gets 404 for a booking in another agenda', function () {
        $booking = cancelBookingAt();

        actingAs(Employee::factory()->create()->user)
            ->post(route('bookings.cancel', $booking))
            ->assertNotFound();
    });
});

it('sends a guest to the login page', function () {
    $booking = cancelBookingAt();

    post(route('bookings.cancel', $booking))->assertRedirect(route('login'));

    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
});

it('refuses a reason longer than 255 characters', function () {
    $booking = cancelBookingAt();

    actingAs($booking->customer)
        ->post(route('bookings.cancel', $booking), ['cancellation_reason' => str_repeat('a', 256)])
        ->assertSessionHasErrors(['cancellation_reason' => 'De reden mag maximaal 255 tekens zijn.']);

    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
});

it('stores an empty reason as null', function () {
    $booking = cancelBookingAt();

    actingAs($booking->customer)->post(route('bookings.cancel', $booking), ['cancellation_reason' => '   ']);

    expect($booking->fresh()?->cancellation_reason)->toBeNull();
});

describe('configurable window', function () {
    it('uses the configured number of hours', function () {
        config(['booking.cancellation_window_hours' => 48]);
        // Starts in 25 hours: inside 48, so too late for the customer.
        $booking = cancelBookingAt();

        expect(cancelRefusal($booking->customer, $booking))
            ->toBe('Online annuleren kan tot 48 uur van tevoren. Neem contact met ons op om deze afspraak te annuleren.');
    });

    it('lets customers cancel until the start with a window of 0', function () {
        config(['booking.cancellation_window_hours' => 0]);
        $booking = cancelBookingAt();
        travelTo(cancelLocal('2026-11-24 09:59'));

        expect(cancelRefusal($booking->customer, $booking))->toBeNull();
    });

    it('says "until the start" when a window of 0 has passed', function () {
        config(['booking.cancellation_window_hours' => 0]);
        $booking = cancelBookingAt();
        travelTo(cancelLocal('2026-11-24 10:01'));

        expect(cancelRefusal($booking->customer, $booking))
            ->toBe('Online annuleren kan tot de afspraak begint. Neem contact met ons op om deze afspraak te annuleren.');
    });

    it('counts real hours across the daylight-saving switch', function () {
        // Sunday 2026-10-25 12:00 is UTC+1; 24 real hours earlier is
        // Saturday 13:00 summer time (UTC+2), not 12:00.
        $booking = cancelBookingAt('2026-10-25 12:00');

        expect($booking->cancellationDeadline()->equalTo(cancelLocal('2026-10-24 13:00')))->toBeTrue();

        travelTo(cancelLocal('2026-10-24 12:30'));
        expect(cancelRefusal($booking->customer, $booking))->toBeNull();
    });
});

it('frees the slot so another customer can book it', function () {
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();
    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
    $employee->services()->attach($service);
    $start = cancelLocal('2026-11-24 10:00');

    $offered = fn (): bool => collect(app(AvailableSlots::class)->for($service, $employee->fresh() ?? $employee, $start))
        ->contains(fn (Slot $slot): bool => $slot->startsAt->equalTo($start));

    $first = app(CreateBooking::class)->handle(User::factory()->customer()->create(), $service, $employee, $start);
    expect($offered())->toBeFalse();

    actingAs($first->customer)->post(route('bookings.cancel', $first))->assertSessionHas('status');
    expect($offered())->toBeTrue();

    $second = app(CreateBooking::class)->handle(User::factory()->customer()->create(), $service, $employee, $start);
    expect($second->starts_at->equalTo($start))->toBeTrue()
        ->and($second->status)->toBe(BookingStatus::Requested);
});

describe('booking page', function () {
    it('shows the cancel form and deadline to the customer before the deadline', function () {
        $booking = cancelBookingAt();

        actingAs($booking->customer)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('action="'.route('bookings.cancel', $booking).'"', false)
            ->assertSee('Je kunt deze afspraak annuleren tot 23-11-2026 10:00');
    });

    it('shows the customer how to cancel after the deadline, without a form', function () {
        $booking = cancelBookingAt();
        travelTo(cancelLocal('2026-11-23 11:00'));

        actingAs($booking->customer)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertDontSee(route('bookings.cancel', $booking))
            ->assertSee('Neem contact met ons op om deze afspraak te annuleren.');
    });

    it('shows the form to an admin after the deadline', function () {
        $booking = cancelBookingAt();
        travelTo(cancelLocal('2026-11-24 09:00'));

        actingAs(User::factory()->admin()->create())->get(route('bookings.show', $booking))
            ->assertSee(route('bookings.cancel', $booking));
    });

    it('shows no form to the employee', function () {
        $booking = cancelBookingAt();
        /** @var Employee $employee */
        $employee = $booking->employee;

        actingAs($employee->user)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertDontSee(route('bookings.cancel', $booking));
    });

    it('shows when and by whom a booking was cancelled, without a form', function () {
        $booking = cancelBookingAt();
        app(CancelBooking::class)->handle(User::factory()->admin()->create(), $booking, 'Medewerker ziek');

        actingAs($booking->customer)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Geannuleerd')
            ->assertSee('door de beheerder')
            ->assertSee('Medewerker ziek')
            ->assertDontSee(route('bookings.cancel', $booking));
    });

    it('is not reachable for a guest', function () {
        get(route('bookings.show', cancelBookingAt()))->assertRedirect(route('login'));
    });
});

/*
 * Edge cases (SUB-18): the spring daylight-saving switch, stale and repeated
 * cancellations, the freed buffer and the page around a refusal.
 */

describe('daylight saving', function () {
    it('counts real hours across the spring switch', function () {
        // Sunday 2027-03-28 12:00 is summer time (UTC+2); 24 real hours
        // earlier is Saturday 11:00 winter time (UTC+1), not 12:00.
        $booking = cancelBookingAt('2027-03-28 12:00');

        expect($booking->cancellationDeadline()->equalTo(cancelLocal('2027-03-27 11:00')))->toBeTrue();

        travelTo(cancelLocal('2027-03-27 11:00'));
        expect(cancelRefusal($booking->customer, $booking))->toBeNull();
    });

    it('refuses the customer just after the spring deadline, though the clock shows under 24 hours to go', function () {
        $booking = cancelBookingAt('2027-03-28 12:00');
        travelTo(cancelLocal('2027-03-27 11:00:01'));

        expect(cancelRefusal($booking->customer, $booking))->toBe(BookingPolicy::pastDeadlineMessage());
    });

    it('handles a booking right after the clocks jump forward', function () {
        // 03:00 summer time on 2027-03-28 is 01:00 UTC; 24 hours earlier is
        // Saturday 02:00 winter time.
        $booking = cancelBookingAt('2027-03-28 03:00');

        expect($booking->cancellationDeadline()->equalTo(cancelLocal('2027-03-27 02:00')))->toBeTrue();
    });

    it('shows the DST-correct deadline on the booking page', function () {
        $booking = cancelBookingAt('2026-10-25 12:00');
        travelTo(cancelLocal('2026-10-24 09:00'));

        actingAs($booking->customer)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Je kunt deze afspraak annuleren tot 24-10-2026 13:00');
    });

    it('confirms the cancellation in local summer time', function () {
        // 10:00 in June is 08:00 UTC; the message must say 10:00.
        $booking = cancelBookingAt('2027-06-15 10:00');

        actingAs($booking->customer)
            ->post(route('bookings.cancel', $booking))
            ->assertSessionHas('status', 'De afspraak op 15-06-2027 om 10:00 is geannuleerd.');
    });
});

describe('repeated and stale cancellations', function () {
    it('tells the customer an already cancelled booking is cancelled, not that the deadline passed', function () {
        $booking = cancelBookingAt(attributes: ['status' => BookingStatus::Cancelled]);
        travelTo(cancelLocal('2026-11-24 09:00'));

        expect(cancelRefusal($booking->customer, $booking))->toBe('Deze afspraak is al geannuleerd.');
    });

    it('re-reads the booking, so a stale copy cannot cancel it again', function () {
        $booking = cancelBookingAt();
        $stale = Booking::query()->findOrFail($booking->id);
        $admin = User::factory()->admin()->create();
        app(CancelBooking::class)->handle($admin, $booking, 'Medewerker ziek');

        expect($stale->status)->toBe(BookingStatus::Confirmed)
            ->and(cancelRefusal($booking->customer, $stale))->toBe('Deze afspraak is al geannuleerd.');

        $booking->refresh();
        expect($booking->cancelled_by)->toBe($admin->id)
            ->and($booking->cancellation_reason)->toBe('Medewerker ziek');
    });

    it('sends a second cancel over HTTP back with the refusal', function () {
        $booking = cancelBookingAt();
        actingAs($booking->customer)->post(route('bookings.cancel', $booking))->assertSessionHas('status');

        actingAs($booking->customer)
            ->post(route('bookings.cancel', $booking))
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('error', 'Deze afspraak is al geannuleerd.');
    });

    it('lets an admin cancel a booking that has already started', function () {
        $booking = cancelBookingAt();
        travelTo(cancelLocal('2026-11-24 10:15'));

        expect(cancelRefusal(User::factory()->admin()->create(), $booking))->toBeNull()
            ->and($booking->fresh()?->status)->toBe(BookingStatus::Cancelled);
    });

    it('refuses the customer once the booking has started, even with a window of 0', function () {
        config(['booking.cancellation_window_hours' => 0]);
        $booking = cancelBookingAt();
        travelTo(cancelLocal('2026-11-24 10:00:01'));

        expect(cancelRefusal($booking->customer, $booking))->toBe(BookingPolicy::pastDeadlineMessage());
    });
});

it('refuses a reason that is not a string', function () {
    $booking = cancelBookingAt();

    actingAs($booking->customer)
        ->post(route('bookings.cancel', $booking), ['cancellation_reason' => ['a', 'b']])
        ->assertSessionHasErrors('cancellation_reason');

    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
});

it('frees the buffer too, so an overlapping later slot is offered again', function () {
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();
    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
    $employee->services()->attach($service);
    $start = cancelLocal('2026-11-24 10:00');
    // 10:30 falls inside the 10:00 booking's buffer (blocked until 10:45).
    $inBuffer = cancelLocal('2026-11-24 10:30');

    $offered = fn (CarbonImmutable $at): bool => collect(app(AvailableSlots::class)->for($service, $employee->fresh() ?? $employee, $start))
        ->contains(fn (Slot $slot): bool => $slot->startsAt->equalTo($at));

    $booking = app(CreateBooking::class)->handle(User::factory()->customer()->create(), $service, $employee, $start);
    expect($offered($inBuffer))->toBeFalse();

    app(CancelBooking::class)->handle($booking->customer, $booking);
    expect($offered($inBuffer))->toBeTrue();
});

describe('booking page around cancelling', function () {
    it('shows the refusal message after a late attempt', function () {
        $booking = cancelBookingAt();
        travelTo(cancelLocal('2026-11-23 11:00'));

        actingAs($booking->customer)
            ->followingRedirects()
            ->post(route('bookings.cancel', $booking))
            ->assertOk()
            ->assertSee('Online annuleren kan tot 24 uur van tevoren.');
    });

    it('shows the confirmation and who cancelled after cancelling', function () {
        $booking = cancelBookingAt();
        $booking->customer->update(['name' => 'Anna de Vries']);

        actingAs($booking->customer)
            ->followingRedirects()
            ->post(route('bookings.cancel', $booking), ['cancellation_reason' => 'Ziek'])
            ->assertOk()
            ->assertSee('De afspraak op 24-11-2026 om 10:00 is geannuleerd.')
            ->assertSee('door Anna de Vries')
            ->assertSee('Ziek')
            ->assertDontSee(route('bookings.cancel', $booking));
    });

    it('does not show the past-deadline text on a cancelled booking', function () {
        $booking = cancelBookingAt(attributes: ['status' => BookingStatus::Cancelled]);
        travelTo(cancelLocal('2026-11-24 09:00'));

        actingAs($booking->customer)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertDontSee('Neem contact met ons op om deze afspraak te annuleren.');
    });

    it('does not show the customer deadline to an admin', function () {
        $booking = cancelBookingAt();

        actingAs(User::factory()->admin()->create())->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee(route('bookings.cancel', $booking))
            ->assertDontSee('Je kunt deze afspraak annuleren tot');
    });

    it('keeps the typed reason after a validation error', function () {
        $booking = cancelBookingAt();
        $tooLong = str_repeat('a', 256);

        actingAs($booking->customer)
            ->from(route('bookings.show', $booking))
            ->followingRedirects()
            ->post(route('bookings.cancel', $booking), ['cancellation_reason' => $tooLong])
            ->assertOk()
            ->assertSee('value="'.$tooLong.'"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('De reden mag maximaal 255 tekens zijn.');
    });
});
