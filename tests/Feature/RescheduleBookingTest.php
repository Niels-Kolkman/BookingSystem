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
use App\Scheduling\AvailableSlots;
use App\Scheduling\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-17: moving a booking to another slot is atomic. The new time is
 * checked first and the old one is only given up when the move succeeds;
 * a refused move leaves the booking (and so its old slot) untouched.
 *
 * "Now" is Monday 2026-11-23 09:00 in Amsterdam. The employee works
 * Monday 08:00-12:00 and Tuesday and Wednesday 09:00-12:00; the service
 * takes 30 minutes plus a 15-minute buffer, offered every 15 minutes. The
 * booking is Tuesday 2026-11-24 10:00, so the customer's deadline (24
 * hours) is Monday 10:00.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 09:00', 'Europe/Amsterdam'));
    config(['booking.cancellation_window_hours' => 24, 'booking.slot_interval' => 15]);
});

function rsLocal(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse($dateTime, 'Europe/Amsterdam');
}

/**
 * @return array{Employee, Service}
 */
function rsAgenda(): array
{
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Monday, '08:00', '12:00')->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Wednesday, '09:00', '12:00')->create();
    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
    $employee->services()->attach($service);

    return [$employee, $service];
}

/**
 * A real booking, made the way a customer makes one.
 */
function rsBook(Employee $employee, Service $service, string $start = '2026-11-24 10:00', ?User $customer = null): Booking
{
    return app(CreateBooking::class)->handle($customer ?? User::factory()->customer()->create(), $service, $employee, rsLocal($start));
}

/**
 * The refusal (either kind), or null when the move went through.
 */
function rsMove(User $by, Booking $booking, string $start): BookingRefused|RescheduleRefused|null
{
    try {
        app(RescheduleBooking::class)->handle($by, $booking, rsLocal($start));
    } catch (BookingRefused|RescheduleRefused $refused) {
        return $refused;
    }

    return null;
}

function rsOffered(Service $service, Employee $employee, string $start): bool
{
    $at = rsLocal($start);

    return collect(app(AvailableSlots::class)->for($service, $employee->fresh() ?? $employee, $at))
        ->contains(fn (Slot $slot): bool => $slot->startsAt->equalTo($at));
}

/**
 * @return array{string, string, string}
 */
function rsTimes(Booking $booking): array
{
    $booking->refresh();

    return [$booking->starts_at->toIso8601String(), $booking->ends_at->toIso8601String(), $booking->blocked_until->toIso8601String()];
}

describe('moving', function () {
    it('moves the booking to the new slot and frees the old one', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        expect(rsMove($booking->customer, $booking, '2026-11-24 11:00'))->toBeNull();

        $booking->refresh();
        expect($booking->starts_at->equalTo(rsLocal('2026-11-24 11:00')))->toBeTrue()
            ->and($booking->ends_at->equalTo(rsLocal('2026-11-24 11:30')))->toBeTrue()
            ->and($booking->blocked_until->equalTo(rsLocal('2026-11-24 11:45')))->toBeTrue()
            ->and($booking->status)->toBe(BookingStatus::Requested)
            ->and(Booking::query()->count())->toBe(1)
            ->and(rsOffered($service, $employee, '2026-11-24 10:00'))->toBeTrue()
            ->and(rsOffered($service, $employee, '2026-11-24 11:00'))->toBeFalse();
    });

    it('moves to a slot overlapping its own old time', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        expect(rsMove($booking->customer, $booking, '2026-11-24 10:15'))->toBeNull()
            ->and($booking->fresh()?->starts_at->equalTo(rsLocal('2026-11-24 10:15')))->toBeTrue();
    });

    it('moves to another day', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        expect(rsMove($booking->customer, $booking, '2026-11-25 09:00'))->toBeNull()
            ->and($booking->fresh()?->starts_at->equalTo(rsLocal('2026-11-25 09:00')))->toBeTrue()
            ->and(rsOffered($service, $employee, '2026-11-24 10:00'))->toBeTrue();
    });

    it('keeps status, notes, price and the booked duration', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        $booking->status = BookingStatus::Confirmed;
        $booking->notes = 'Graag kort';
        $booking->save();
        $price = $booking->price_cents;
        // Changing the service later does not change the booked duration.
        $service->update(['duration_minutes' => 60, 'price_cents' => $price + 1000]);

        expect(rsMove($booking->customer, $booking, '2026-11-24 11:00'))->toBeNull();

        $booking->refresh();
        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->notes)->toBe('Graag kort')
            ->and($booking->duration_minutes)->toBe(30)
            ->and($booking->price_cents)->toBe($price)
            ->and($booking->ends_at->equalTo(rsLocal('2026-11-24 11:30')))->toBeTrue();
    });

    it('resets the reminder so the new time gets one', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        $booking->setAttribute('reminder_sent_at', CarbonImmutable::now());
        $booking->save();

        rsMove($booking->customer, $booking, '2026-11-24 11:00');

        expect($booking->fresh()?->getAttribute('reminder_sent_at'))->toBeNull();
    });
});

describe('atomic: a refused move changes nothing', function () {
    it('keeps the old time when the new slot is taken', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        rsBook($employee, $service, '2026-11-24 11:00');
        $before = rsTimes($booking);

        $refused = rsMove($booking->customer, $booking, '2026-11-24 11:00');

        expect($refused)->toBeInstanceOf(BookingRefused::class)
            ->and($refused?->getMessage())->toBe('Dit tijdstip is helaas net bezet. Kies een ander tijdstip.')
            ->and(rsTimes($booking))->toBe($before)
            // The old slot is still taken: nobody else can book it.
            ->and(rsOffered($service, $employee, '2026-11-24 10:00'))->toBeFalse()
            ->and(fn () => rsBook($employee, $service))->toThrow(BookingRefused::class);
    });

    it('counts the buffer, and allows touching the other booking', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        rsBook($employee, $service, '2026-11-24 11:00');

        // 10:30 + 30 min + 15 min buffer is blocked until 11:15, past the other start.
        expect(rsMove($booking->customer, $booking, '2026-11-24 10:30'))->toBeInstanceOf(BookingRefused::class);
        // 10:15 is blocked until exactly 11:00: touching is fine.
        expect(rsMove($booking->customer, $booking, '2026-11-24 10:15'))->toBeNull();
    });

    it('refuses when the customer has another appointment then, worded for who moves it', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        [$otherEmployee, $otherService] = rsAgenda();
        /** @var User $customer */
        $customer = $booking->customer;
        rsBook($otherEmployee, $otherService, '2026-11-24 11:00', $customer);
        $before = rsTimes($booking);

        expect(rsMove($customer, $booking, '2026-11-24 11:00')?->getMessage())->toBe('Je hebt op dit tijdstip al een andere afspraak.')
            ->and(rsMove(User::factory()->admin()->create(), $booking, '2026-11-24 11:00')?->getMessage())->toBe('De klant heeft op dit tijdstip al een andere afspraak.')
            ->and(rsTimes($booking))->toBe($before);
    });

    it('refuses a time that is not offered', function (string $start) {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        $before = rsTimes($booking);

        $refused = rsMove($booking->customer, $booking, $start);

        expect($refused instanceof BookingRefused ? $refused->reason : null)->toBe(BookingRefused::REASON_NOT_OFFERED)
            ->and(rsTimes($booking))->toBe($before);
    })->with([
        'before working time' => '2026-11-24 08:00',
        'does not fit before closing' => '2026-11-24 11:45',
        'off the interval grid' => '2026-11-24 10:05',
        'day off' => '2026-11-26 10:00',
        'in the past, within working time' => '2026-11-23 08:30',
    ]);

    it('refuses the time the booking already has', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        $refused = rsMove($booking->customer, $booking, '2026-11-24 10:00');

        expect($refused instanceof BookingRefused ? $refused->reason : null)->toBe(BookingRefused::REASON_SAME_SLOT);
    });

    it('refuses when the employee can no longer perform the service', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        $employee->services()->detach($service);

        $refused = rsMove($booking->customer, $booking, '2026-11-24 11:00');

        expect($refused instanceof BookingRefused ? $refused->reason : null)->toBe(BookingRefused::REASON_NOT_BOOKABLE);
    });

    it('rolls the move back when saving fails, so the old time stays', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        $before = rsTimes($booking);
        Booking::saved(function (): void {
            throw new RuntimeException('Database gone');
        });

        expect(fn () => rsMove($booking->customer, $booking, '2026-11-24 11:00'))->toThrow(RuntimeException::class, 'Database gone');

        Booking::flushEventListeners();
        expect(rsTimes($booking))->toBe($before);
    });

    it('locks the employee, the customer and the booking first, and closes its transaction on a refusal', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        $level = DB::transactionLevel();
        $inTransaction = false;
        $queries = [];
        Event::listen(TransactionBeginning::class, function () use (&$inTransaction): void {
            $inTransaction = true;
        });
        DB::listen(function (QueryExecuted $query) use (&$inTransaction, &$queries): void {
            if ($inTransaction) {
                $queries[] = $query->sql;
            }
        });

        expect(rsMove($booking->customer, $booking, '2026-11-24 08:00'))->toBeInstanceOf(BookingRefused::class)
            ->and($queries[0])->toMatch('/^select .* from [`"]employees[`"] where /')
            ->and($queries[1])->toMatch('/^select .* from [`"]users[`"] where /')
            ->and($queries[2])->toMatch('/^select .* from [`"]bookings[`"] where /')
            ->and(DB::transactionLevel())->toBe($level);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            expect($queries[0])->toEndWith(' for update')
                ->and($queries[1])->toEndWith(' for update')
                ->and($queries[2])->toEndWith(' for update');
        }
    });
});

describe('who may move', function () {
    it('lets the customer move until the deadline, which then follows the new start', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        travelTo(rsLocal('2026-11-23 10:00'));
        expect(rsMove($booking->customer, $booking, '2026-11-24 11:00'))->toBeNull();

        travelTo(rsLocal('2026-11-23 11:00:01'));
        expect(rsMove($booking->customer, $booking, '2026-11-24 09:00')?->getMessage())
            ->toBe('Online verplaatsen kan tot 24 uur van tevoren. Neem contact met ons op om deze afspraak te verplaatsen.');
    });

    it('lets an admin move after the customer deadline', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        travelTo(rsLocal('2026-11-24 09:00'));

        expect(rsMove(User::factory()->admin()->create(), $booking, '2026-11-24 11:00'))->toBeNull();
    });

    it('does not let the employee move a booking in their own agenda', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        expect(rsMove($employee->user, $booking, '2026-11-24 11:00')?->getMessage())
            ->toBe('Alleen de klant of de beheerder kan deze afspraak verplaatsen.');
    });

    it('refuses the booking of another customer as not found', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        $refused = rsMove(User::factory()->customer()->create(), $booking, '2026-11-24 11:00');

        expect($refused instanceof RescheduleRefused && $refused->notFound)->toBeTrue();
    });

    it('cannot move a cancelled, completed or no-show booking', function (BookingStatus $status, string $message) {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        $booking->status = $status;
        $booking->save();

        expect(rsMove(User::factory()->admin()->create(), $booking, '2026-11-24 11:00')?->getMessage())->toBe($message)
            ->and($booking->fresh()?->starts_at->equalTo(rsLocal('2026-11-24 10:00')))->toBeTrue();
    })->with([
        'cancelled' => [BookingStatus::Cancelled, 'Deze afspraak is geannuleerd en kan niet meer worden verplaatst.'],
        'completed' => [BookingStatus::Completed, 'Deze afspraak kan niet meer worden verplaatst.'],
        'no-show' => [BookingStatus::NoShow, 'Deze afspraak kan niet meer worden verplaatst.'],
    ]);

    it('judges a stale copy on the fresh row', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        $stale = Booking::query()->findOrFail($booking->id);
        $booking->status = BookingStatus::Cancelled;
        $booking->save();

        expect(rsMove($booking->customer, $stale, '2026-11-24 11:00'))->toBeInstanceOf(RescheduleRefused::class)
            ->and($booking->fresh()?->starts_at->equalTo(rsLocal('2026-11-24 10:00')))->toBeTrue();
    });
});

describe('pages', function () {
    it('shows the free times without the current one, with times overlapping it', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        $response = actingAs($booking->customer)->get(route('bookings.reschedule', $booking))->assertOk();

        /** @var list<Slot> $slots */
        $slots = $response->viewData('slots');
        $labels = array_map(fn (Slot $slot): string => $slot->label(), $slots);
        expect($response->viewData('date'))->toBe('2026-11-24')
            ->and($labels)->not->toContain('10:00')
            ->and($labels)->toContain('09:45', '10:15', '11:00');
    });

    it('shows the free times of the chosen date', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        actingAs($booking->customer)->get(route('bookings.reschedule', [$booking, 'date' => '2026-11-25']))
            ->assertOk()
            ->assertViewHas('date', '2026-11-25')
            ->assertSee(rsLocal('2026-11-25 10:00')->toIso8601String());
    });

    it('moves the booking and confirms the new time', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        actingAs($booking->customer)
            ->post(route('bookings.reschedule.store', $booking), ['starts_at' => '2026-11-24T11:00:00+01:00'])
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('status', 'De afspraak is verplaatst naar 24-11-2026 om 11:00.');

        expect($booking->fresh()?->starts_at->equalTo(rsLocal('2026-11-24 11:00')))->toBeTrue();
    });

    it('goes back to the page with the message when the new time is taken', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        rsBook($employee, $service, '2026-11-24 11:00');

        actingAs($booking->customer)
            ->post(route('bookings.reschedule.store', $booking), ['starts_at' => '2026-11-24T11:00:00+01:00'])
            ->assertRedirect(route('bookings.reschedule', $booking))
            ->assertSessionHasErrors(['starts_at' => 'Dit tijdstip is helaas net bezet. Kies een ander tijdstip.']);

        actingAs($booking->customer)->get(route('bookings.reschedule', $booking))
            ->assertSee('Dit tijdstip is helaas net bezet.');
    });

    it('validates the new time', function (?string $startsAt) {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        actingAs($booking->customer)
            ->post(route('bookings.reschedule.store', $booking), ['starts_at' => $startsAt])
            ->assertSessionHasErrors(['starts_at' => 'Kies een nieuw tijdstip.']);
    })->with([
        'missing' => [null],
        'without offset' => ['2026-11-24 11:00'],
        'Z instead of offset' => ['2026-11-24T10:00:00Z'],
    ]);

    it('sends the customer back to the booking after the deadline', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        travelTo(rsLocal('2026-11-23 11:00'));

        actingAs($booking->customer)->get(route('bookings.reschedule', $booking))
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('error', 'Online verplaatsen kan tot 24 uur van tevoren. Neem contact met ons op om deze afspraak te verplaatsen.');

        actingAs($booking->customer)
            ->post(route('bookings.reschedule.store', $booking), ['starts_at' => '2026-11-24T11:00:00+01:00'])
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('error');

        expect($booking->fresh()?->starts_at->equalTo(rsLocal('2026-11-24 10:00')))->toBeTrue();
    });

    it('answers 404 for the booking of another customer', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        $other = User::factory()->customer()->create();

        actingAs($other)->get(route('bookings.reschedule', $booking))->assertNotFound();
        actingAs($other)->post(route('bookings.reschedule.store', $booking), ['starts_at' => '2026-11-24T11:00:00+01:00'])->assertNotFound();
    });

    it('sends a guest to the login page', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);

        get(route('bookings.reschedule', $booking))->assertRedirect(route('login'));
        post(route('bookings.reschedule.store', $booking))->assertRedirect(route('login'));
    });

    it('links to the page from the booking for the customer and the admin, not the employee', function () {
        [$employee, $service] = rsAgenda();
        $booking = rsBook($employee, $service);
        $link = route('bookings.reschedule', $booking);

        actingAs($booking->customer)->get(route('bookings.show', $booking))->assertSee($link);
        actingAs(User::factory()->admin()->create())->get(route('bookings.show', $booking))->assertSee($link);
        actingAs($employee->user)->get(route('bookings.show', $booking))->assertDontSee($link);
    });
});
