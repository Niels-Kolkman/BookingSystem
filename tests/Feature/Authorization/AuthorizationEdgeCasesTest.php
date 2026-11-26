<?php

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Edge cases for ISSUE-6 that BookingAccessTest does not cover. Self-contained
 * on purpose, so this file also runs on its own.
 *
 * @return array<int, mixed>
 */
function edgeVisibleIds(User $user): array
{
    return Booking::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
}

function edgeBookingLink(Booking $booking): string
{
    return 'href="'.route('bookings.show', $booking).'"';
}

describe('EmployeePolicy::viewAgenda', function () {
    it('allows only the owner and admins', function () {
        $xavier = Employee::factory()->create();
        $yara = Employee::factory()->create();
        $customer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $loginWithoutProfile = User::factory()->employee()->create();

        $gate = fn (User $user, Employee $employee) => Gate::forUser($user)->inspect('viewAgenda', $employee);

        expect($gate($xavier->user, $xavier)->allowed())->toBeTrue()
            ->and($gate($xavier->user, $yara)->allowed())->toBeFalse()
            ->and($gate($admin, $xavier)->allowed())->toBeTrue()
            ->and($gate($admin, $yara)->allowed())->toBeTrue()
            ->and($gate($customer, $xavier)->allowed())->toBeFalse()
            ->and($gate($loginWithoutProfile, $xavier)->allowed())->toBeFalse();
    });

    it('denies as not found so agendas cannot be probed', function () {
        $xavier = Employee::factory()->create();
        $yara = Employee::factory()->create();

        expect(Gate::forUser($xavier->user)->inspect('viewAgenda', $yara)->status())->toBe(404);
    });
});

describe('a demoted employee', function () {
    it('loses access to their former agenda and its bookings', function () {
        $employee = Employee::factory()->create();
        $agendaBooking = Booking::factory()->for($employee)->create();

        // Role changed to customer, but the employee profile still exists.
        $user = $employee->user;
        $user->role = UserRole::Customer;
        $user->save();

        expect(edgeVisibleIds($user))->toBe([])
            ->and(Gate::forUser($user)->allows('view', $agendaBooking))->toBeFalse()
            ->and(Gate::forUser($user)->allows('viewAgenda', $employee))->toBeFalse();

        actingAs($user)->get(route('agenda'))->assertForbidden();
        actingAs($user)->get(route('employees.agenda', $employee))->assertForbidden();
        actingAs($user)->get(route('bookings.show', $agendaBooking))->assertNotFound();
        actingAs($user)->get(route('bookings.index'))
            ->assertOk()
            ->assertDontSee(edgeBookingLink($agendaBooking), false);
    });
});

describe('an employee who is also the customer of a booking', function () {
    it('gets the same answer from the policy and the visibleTo scope', function () {
        $xavier = Employee::factory()->create();
        $yara = Employee::factory()->create();

        // Xavier booked an appointment with Yara for himself.
        $booking = Booking::factory()->for($xavier->user, 'customer')->for($yara)->create();

        expect(Gate::forUser($xavier->user)->allows('view', $booking))
            ->toBe(in_array($booking->id, edgeVisibleIds($xavier->user), true));
    });
});

describe('Booking::visibleTo combined with other constraints', function () {
    it('still limits a customer when the query already has an orWhere', function () {
        $anna = User::factory()->customer()->create();
        $own = Booking::factory()->for($anna, 'customer')->create();
        Booking::factory()->create(['status' => BookingStatus::Confirmed]);
        Booking::factory()->create(['status' => BookingStatus::Cancelled]);

        $ids = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->orWhere('status', BookingStatus::Cancelled)
            ->visibleTo($anna)
            ->pluck('id')
            ->all();

        expect($ids)->toBe([$own->id]);
    });

    it('still limits an employee when the query already has an orWhere', function () {
        $xavier = Employee::factory()->create();
        $own = Booking::factory()->for($xavier)->create();
        Booking::factory()->create(['status' => BookingStatus::Cancelled]);

        $ids = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->orWhere('status', BookingStatus::Cancelled)
            ->visibleTo($xavier->user)
            ->pluck('id')
            ->all();

        expect($ids)->toBe([$own->id]);
    });
});

describe('paginated lists', function () {
    it('count and page only the customer\'s own bookings', function () {
        $anna = User::factory()->customer()->create();
        $employee = Employee::factory()->create();
        $own = Booking::factory()->count(26)->for($anna, 'customer')->for($employee)->create();
        $others = Booking::factory()->count(3)->for($employee)->create();

        $page1 = actingAs($anna)->get(route('bookings.index'))->assertOk();
        $page2 = actingAs($anna)->get(route('bookings.index', ['page' => 2]))->assertOk();

        expect($page1->viewData('bookings')->total())->toBe(26)
            ->and($page1->viewData('bookings')->count())->toBe(25)
            ->and($page2->viewData('bookings')->count())->toBe(1);

        foreach ($others as $other) {
            $page1->assertDontSee(edgeBookingLink($other), false);
            $page2->assertDontSee(edgeBookingLink($other), false);
        }

        $shown = [
            ...$page1->viewData('bookings')->pluck('id')->all(),
            ...$page2->viewData('bookings')->pluck('id')->all(),
        ];
        sort($shown);

        expect($shown)->toBe($own->pluck('id')->sort()->values()->all());
    });

    it('show only that employee\'s bookings in an agenda week', function () {
        $xavier = Employee::factory()->create();
        // Monday 2026-11-30 09:00 UTC, one booking a day for the whole week.
        $monday = CarbonImmutable::parse('2026-11-30 09:00', 'UTC');
        $own = collect(range(0, 6))->map(fn (int $i): Booking => Booking::factory()->for($xavier)->create([
            'starts_at' => $monday->addDays($i),
            'ends_at' => $monday->addDays($i)->addMinutes(30),
            'blocked_until' => $monday->addDays($i)->addMinutes(30),
        ]));
        $others = Booking::factory()->count(2)->create([
            'starts_at' => $monday,
            'ends_at' => $monday->addMinutes(30),
            'blocked_until' => $monday->addMinutes(30),
        ]);

        $response = actingAs($xavier->user)
            ->get(route('employees.agenda', [$xavier, 'date' => '2026-12-03']))
            ->assertOk();

        expect(substr_count((string) $response->getContent(), 'data-agenda-item="booking"'))->toBe(7);
        foreach ($own as $booking) {
            $response->assertSee(edgeBookingLink($booking), false);
        }
        foreach ($others as $other) {
            $response->assertDontSee(edgeBookingLink($other), false);
        }
    });
});

describe('agenda routes', function () {
    it('send a guest to the login page', function () {
        $employee = Employee::factory()->create();

        get(route('agenda'))->assertRedirect(route('login'));
        get(route('employees.agenda', $employee))->assertRedirect(route('login'));
    });

    it('do not give an admin a personal agenda', function () {
        actingAs(User::factory()->admin()->create())->get(route('agenda'))->assertForbidden();
    });

    it('answer not found for an agenda that does not exist', function () {
        actingAs(User::factory()->admin()->create())
            ->get(route('employees.agenda', 999))
            ->assertNotFound();
    });
});

describe('booking pages', function () {
    it('show the booking details to the customer', function () {
        $anna = User::factory()->customer()->create();
        $booking = Booking::factory()->for($anna, 'customer')->create();

        actingAs($anna)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee($booking->service->name)
            ->assertSee($booking->employee->display_name)
            ->assertSee($anna->name);
    });

    it('title the list "Mijn boekingen" for customers and "Boekingen" for staff', function () {
        actingAs(User::factory()->customer()->create())->get(route('bookings.index'))
            ->assertViewHas('title', 'Mijn boekingen');
        actingAs(Employee::factory()->create()->user)->get(route('bookings.index'))
            ->assertViewHas('title', 'Boekingen');
        actingAs(User::factory()->admin()->create())->get(route('bookings.index'))
            ->assertViewHas('title', 'Boekingen');
    });
});
