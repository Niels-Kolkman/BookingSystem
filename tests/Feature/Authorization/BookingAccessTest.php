<?php

use App\Models\Booking;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
| Two customers (anna, bram), two employees (xavier, yara) and an admin.
|   own      = anna  with xavier
|   other    = bram  with yara
|   annaYara = anna  with yara
*/
beforeEach(function () {
    $this->anna = User::factory()->customer()->create();
    $this->bram = User::factory()->customer()->create();
    $this->xavier = Employee::factory()->create();
    $this->yara = Employee::factory()->create();
    $this->admin = User::factory()->admin()->create();

    $this->own = Booking::factory()->for($this->anna, 'customer')->for($this->xavier)->create();
    $this->other = Booking::factory()->for($this->bram, 'customer')->for($this->yara)->create();
    $this->annaYara = Booking::factory()->for($this->anna, 'customer')->for($this->yara)->create();
});

function visibleIds(User $user): array
{
    return Booking::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
}

function bookingLink(Booking $booking): string
{
    return 'href="'.route('bookings.show', $booking).'"';
}

describe('Booking::visibleTo', function () {
    it('limits a customer to their own bookings', function () {
        expect(visibleIds($this->anna))->toBe([$this->own->id, $this->annaYara->id])
            ->and(visibleIds($this->bram))->toBe([$this->other->id]);
    });

    it('limits an employee to the bookings in their own agenda', function () {
        expect(visibleIds($this->xavier->user))->toBe([$this->own->id])
            ->and(visibleIds($this->yara->user))->toBe([$this->other->id, $this->annaYara->id]);
    });

    it('shows an admin every booking', function () {
        expect(visibleIds($this->admin))->toBe([$this->own->id, $this->other->id, $this->annaYara->id]);
    });

    it('shows nothing to an employee login without an employee profile', function () {
        expect(visibleIds(User::factory()->employee()->create()))->toBe([]);
    });

    it('shows nothing to a new customer', function () {
        expect(visibleIds(User::factory()->customer()->create()))->toBe([]);
    });
});

describe('BookingPolicy', function () {
    it('agrees with visibleTo for every user and booking', function () {
        $users = [$this->anna, $this->bram, $this->xavier->user, $this->yara->user, $this->admin];

        foreach ($users as $user) {
            $visible = visibleIds($user);

            foreach (Booking::all() as $booking) {
                expect(Gate::forUser($user)->allows('view', $booking))
                    ->toBe(in_array($booking->id, $visible, true), "user {$user->id}, booking {$booking->id}");
            }
        }
    });

    it('lets every role open the bookings list', function () {
        foreach ([$this->anna, $this->xavier->user, $this->admin] as $user) {
            expect(Gate::forUser($user)->allows('viewAny', Booking::class))->toBeTrue();
        }
    });
});

describe('bookings pages', function () {
    it('sends a guest to the login page', function () {
        get(route('bookings.index'))->assertRedirect(route('login'));
        get(route('bookings.show', $this->own))->assertRedirect(route('login'));
    });

    it('lists only the customer\'s own bookings', function () {
        actingAs($this->anna)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('Mijn boekingen')
            ->assertSee(bookingLink($this->own), false)
            ->assertSee(bookingLink($this->annaYara), false)
            ->assertDontSee(bookingLink($this->other), false);
    });

    it('lists only the employee\'s own agenda bookings', function () {
        actingAs($this->xavier->user)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee(bookingLink($this->own), false)
            ->assertDontSee(bookingLink($this->other), false)
            ->assertDontSee(bookingLink($this->annaYara), false);
    });

    it('lists every booking for an admin', function () {
        actingAs($this->admin)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee(bookingLink($this->own), false)
            ->assertSee(bookingLink($this->other), false)
            ->assertSee(bookingLink($this->annaYara), false);
    });

    it('shows a customer their own booking', function () {
        actingAs($this->anna)->get(route('bookings.show', $this->own))->assertOk();
    });

    it('hides someone else\'s booking from a customer as not found', function () {
        actingAs($this->anna)->get(route('bookings.show', $this->other))->assertNotFound();
    });

    it('shows an employee a booking in their agenda only', function () {
        actingAs($this->xavier->user)->get(route('bookings.show', $this->own))->assertOk();
        actingAs($this->xavier->user)->get(route('bookings.show', $this->annaYara))->assertNotFound();
    });

    it('shows an admin any booking', function () {
        foreach ([$this->own, $this->other, $this->annaYara] as $booking) {
            actingAs($this->admin)->get(route('bookings.show', $booking))->assertOk();
        }
    });

    it('answers not found for a booking id that does not exist', function () {
        actingAs($this->anna)->get(route('bookings.show', 999))->assertNotFound();
    });
});

describe('agenda', function () {
    it('sends an employee to their own agenda', function () {
        actingAs($this->xavier->user)
            ->get(route('agenda'))
            ->assertRedirect(route('employees.agenda', $this->xavier));
    });

    it('shows an employee their own agenda', function () {
        actingAs($this->xavier->user)
            ->get(route('employees.agenda', $this->xavier))
            ->assertOk()
            ->assertSee(bookingLink($this->own), false)
            ->assertDontSee(bookingLink($this->other), false);
    });

    it('hides another employee\'s agenda as not found', function () {
        actingAs($this->xavier->user)
            ->get(route('employees.agenda', $this->yara))
            ->assertNotFound();
    });

    it('gives an employee login without a profile no agenda', function () {
        actingAs(User::factory()->employee()->create())
            ->get(route('agenda'))
            ->assertNotFound();
    });

    it('does not give customers an agenda', function () {
        actingAs($this->anna)->get(route('agenda'))->assertForbidden();
        actingAs($this->anna)->get(route('employees.agenda', $this->xavier))->assertForbidden();
    });

    it('shows an admin every agenda', function () {
        actingAs($this->admin)
            ->get(route('employees.agenda', $this->yara))
            ->assertOk()
            ->assertSee(bookingLink($this->other), false)
            ->assertSee(bookingLink($this->annaYara), false)
            ->assertDontSee(bookingLink($this->own), false);
    });
});

describe('admin area', function () {
    it('is only open to admins', function () {
        actingAs($this->anna)->get(route('admin.dashboard'))->assertForbidden();
        actingAs($this->xavier->user)->get(route('admin.dashboard'))->assertForbidden();
        actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Beheer');
    });

    it('sends a guest to the login page', function () {
        get(route('admin.dashboard'))->assertRedirect(route('login'));
    });
});

describe('navigation', function () {
    it('shows each role only its own links', function () {
        actingAs($this->anna)->get(route('dashboard'))
            ->assertSee('Mijn boekingen')
            ->assertDontSee('Mijn agenda')
            ->assertDontSee(route('admin.dashboard'));

        actingAs($this->xavier->user)->get(route('dashboard'))
            ->assertSee('Mijn agenda')
            ->assertDontSee(route('admin.dashboard'));

        actingAs($this->admin)->get(route('dashboard'))
            ->assertSee(route('admin.dashboard'))
            ->assertDontSee('Mijn agenda');
    });
});
