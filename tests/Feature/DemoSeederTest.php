<?php

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Scheduling\AvailableSlots;
use App\Scheduling\DutchHolidays;
use App\Scheduling\LocalTime;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;
use function Pest\Laravel\travelTo;

/*
| The demo seeder (ISSUE-26). It runs on a few fixed moments, one in the week
| the clock goes back and one just before Christmas, so the checks do not
| depend on the day the suite runs.
*/

dataset('demo moments', [
    'normal week' => '2026-11-26 10:17:00',
    'clock goes back' => '2026-10-27 08:00:00',
    'christmas' => '2026-12-23 19:30:00',
]);

function seedDemo(string $now): CarbonImmutable
{
    $moment = CarbonImmutable::parse($now, DutchHolidays::TIMEZONE);
    travelTo($moment);
    seed(DatabaseSeeder::class);

    return $moment;
}

it('creates a complete salon with known logins', function (string $now) {
    seedDemo($now);

    $admin = User::query()->where('email', DemoSeeder::ADMIN_EMAIL)->firstOrFail();
    expect($admin->role)->toBe(UserRole::Admin)
        ->and(Hash::check('password', $admin->password))->toBeTrue();

    expect(Service::query()->active()->count())->toBe(8)
        ->and(Service::query()->where('is_active', false)->count())->toBe(1)
        ->and(Employee::query()->active()->count())->toBe(3)
        ->and(Employee::query()->where('is_active', false)->count())->toBe(1)
        ->and(User::query()->where('role', UserRole::Customer)->count())->toBe(24)
        ->and(User::query()->distinct()->count('email'))->toBe(User::query()->count());

    foreach (Employee::query()->with('user')->get() as $employee) {
        expect($employee->user->role)->toBe(UserRole::Employee);
    }
    foreach (Employee::query()->active()->get() as $employee) {
        expect($employee->workingHours()->count())->toBeGreaterThan(0)
            ->and($employee->services()->active()->count())->toBeGreaterThan(0);
    }

    expect(Booking::query()->count())->toBeGreaterThan(150);
    foreach (BookingStatus::cases() as $status) {
        expect(Booking::query()->where('status', $status)->count())
            ->toBeGreaterThan(0, "no {$status->value} bookings");
    }
})->with('demo moments');

it('gives the demo customer past, upcoming and cancelled bookings', function (string $now) {
    $moment = seedDemo($now);

    $bookings = User::query()->where('email', DemoSeeder::CUSTOMER_EMAIL)->firstOrFail()->bookings;

    expect($bookings->where('status', BookingStatus::Completed)->count())->toBe(2)
        ->and($bookings->where('status', BookingStatus::Cancelled)->count())->toBeGreaterThanOrEqual(2)
        ->and($bookings->where('status', BookingStatus::Requested)->count())->toBeGreaterThanOrEqual(1)
        ->and($bookings->filter(fn (Booking $b) => $b->status->blocksTime() && $b->starts_at > $moment)->count())->toBe(2);
})->with('demo moments');

it('only books what the system itself allows, without double bookings', function (string $now) {
    $moment = seedDemo($now);

    $bookings = Booking::query()->with(['service', 'employee.workingHours', 'employee.scheduleExceptions'])->get();

    foreach ($bookings as $booking) {
        // The snapshot matches the service and the times are consistent.
        expect($booking->duration_minutes)->toBe($booking->service->duration_minutes)
            ->and($booking->price_cents)->toBe($booking->service->price_cents)
            ->and($booking->ends_at->equalTo($booking->starts_at->addMinutes($booking->duration_minutes)))->toBeTrue()
            ->and($booking->blocked_until->equalTo($booking->ends_at->addMinutes($booking->service->buffer_minutes)))->toBeTrue()
            ->and($booking->employee->is_active)->toBeTrue();

        // Within the working time of that day, holidays and time off applied.
        $local = LocalTime::toLocal($booking->starts_at);
        $start = $local->hour * 60 + $local->minute;
        $inside = collect($booking->employee->scheduleOn($local)->ranges)
            ->contains(fn (TimeRange $r) => $start >= $r->start && $start + $booking->duration_minutes <= $r->end);
        expect($inside)->toBeTrue("booking {$booking->id} lies outside the working time");

        // The status fits the moment.
        if ($booking->ends_at <= $moment) {
            expect($booking->status)->not->toBe(BookingStatus::Requested);
        }
        if ($booking->starts_at > $moment) {
            expect($booking->status)->toBeIn([BookingStatus::Requested, BookingStatus::Confirmed, BookingStatus::Cancelled]);
        }

        expect($booking->created_at <= $moment)->toBeTrue();
        if ($booking->status === BookingStatus::Cancelled) {
            expect($booking->cancelled_at)->not->toBeNull()
                ->and($booking->cancelled_by)->not->toBeNull()
                ->and($booking->cancelled_at <= $moment)->toBeTrue()
                ->and($booking->cancelled_at >= $booking->created_at)->toBeTrue();

            // A customer only cancels before the cancellation deadline.
            if ($booking->cancelled_by === $booking->customer_id) {
                expect($booking->cancelled_at <= $booking->cancellationDeadline())->toBeTrue();
            }
        } else {
            expect($booking->cancelled_at)->toBeNull();
        }
    }

    // Nobody is double booked: an employee including the buffer, a
    // customer only for the appointment itself.
    $kept = Booking::query()->where('status', '!=', BookingStatus::Cancelled)->orderBy('starts_at')->get();

    foreach ($kept->groupBy('employee_id') as $agenda) {
        $agenda = $agenda->values();
        for ($i = 1; $i < $agenda->count(); $i++) {
            expect($agenda[$i]->starts_at >= $agenda[$i - 1]->blocked_until)
                ->toBeTrue("employee overlap at booking {$agenda[$i]->id}");
        }
    }

    foreach ($kept->groupBy('customer_id') as $agenda) {
        $agenda = $agenda->values();
        for ($i = 1; $i < $agenda->count(); $i++) {
            expect($agenda[$i]->starts_at >= $agenda[$i - 1]->ends_at)
                ->toBeTrue("customer overlap at booking {$agenda[$i]->id}");
        }
    }
})->with('demo moments');

it('leaves free slots with every employee in the coming week', function () {
    $moment = seedDemo('2026-11-26 10:17:00');
    $slots = AvailableSlots::fromConfig();

    foreach (Employee::query()->active()->get() as $employee) {
        $service = $employee->services()->active()->orderBy('duration_minutes')->firstOrFail();
        $free = 0;
        for ($day = 1; $day <= 7; $day++) {
            $free += count($slots->for($service, $employee, LocalTime::midnight($moment)->addDays($day)));
        }

        expect($free)->toBeGreaterThan(0, "{$employee->display_name} is fully booked");
    }
});

it('shows the demo on the main screens', function () {
    seedDemo('2026-11-26 10:17:00');
    $user = fn (string $email): User => User::query()->where('email', $email)->firstOrFail();

    actingAs($user(DemoSeeder::ADMIN_EMAIL))->get(route('admin.bookings.index'))->assertOk()->assertSee('Noor');
    actingAs($user('noor@example.com'))->followingRedirects()->get(route('agenda'))->assertOk()->assertSee('Noor');
    actingAs($user(DemoSeeder::CUSTOMER_EMAIL))->get(route('bookings.index'))->assertOk()->assertSee('Knippen dames');
    actingAs($user(DemoSeeder::CUSTOMER_EMAIL))->get(route('bookings.create'))->assertOk();
});

it('queues no mails and marks due reminders as sent', function () {
    Queue::fake();
    $moment = seedDemo('2026-11-26 10:17:00');

    Queue::assertNothingPushed();

    artisan('bookings:send-reminders')->assertSuccessful();
    Queue::assertNothingPushed();

    expect(Booking::query()->where('starts_at', '>', $moment->addDays(2))->whereNotNull('reminder_sent_at')->count())->toBe(0);
});

it('runs only once', function () {
    seedDemo('2026-11-26 10:17:00');
    $counts = [User::query()->count(), Booking::query()->count(), Service::query()->count()];

    seed(DatabaseSeeder::class);

    expect([User::query()->count(), Booking::query()->count(), Service::query()->count()])->toBe($counts);
});
