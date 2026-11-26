<?php

use App\Models\Booking;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Two customers (anna, bram), two employees (xavier, yara) and an admin.
 *   own      = anna with xavier
 *   other    = bram with yara
 *   annaYara = anna with yara
 */
final class AccessScenario
{
    public function __construct(
        public User $anna,
        public User $bram,
        public Employee $xavier,
        public Employee $yara,
        public User $admin,
        public Booking $own,
        public Booking $other,
        public Booking $annaYara,
    ) {}

    public static function create(): self
    {
        $anna = User::factory()->customer()->create();
        $bram = User::factory()->customer()->create();
        $xavier = Employee::factory()->create();
        $yara = Employee::factory()->create();

        // All on one day, so one agenda week shows them all (ISSUE-21).
        $at = fn (int $hour): array => [
            'starts_at' => agendaDay()->setTime($hour, 0),
            'ends_at' => agendaDay()->setTime($hour, 30),
            'blocked_until' => agendaDay()->setTime($hour, 30),
        ];

        return new self(
            anna: $anna,
            bram: $bram,
            xavier: $xavier,
            yara: $yara,
            admin: User::factory()->admin()->create(),
            own: Booking::factory()->for($anna, 'customer')->for($xavier)->create($at(9)),
            other: Booking::factory()->for($bram, 'customer')->for($yara)->create($at(10)),
            annaYara: Booking::factory()->for($anna, 'customer')->for($yara)->create($at(11)),
        );
    }
}

/**
 * The day of the scenario's bookings, as UTC midnight three days from now.
 */
function agendaDay(): CarbonImmutable
{
    return CarbonImmutable::now('UTC')->addDays(3)->startOfDay();
}

/**
 * The agenda week that holds the scenario's bookings.
 */
function agendaUrl(Employee $employee): string
{
    return route('employees.agenda', [$employee, 'date' => agendaDay()->format('Y-m-d')]);
}

/**
 * @return list<int>
 */
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
        $s = AccessScenario::create();

        expect(visibleIds($s->anna))->toBe([$s->own->id, $s->annaYara->id])
            ->and(visibleIds($s->bram))->toBe([$s->other->id]);
    });

    it('limits an employee to the bookings in their own agenda', function () {
        $s = AccessScenario::create();

        expect(visibleIds($s->xavier->user))->toBe([$s->own->id])
            ->and(visibleIds($s->yara->user))->toBe([$s->other->id, $s->annaYara->id]);
    });

    it('shows an admin every booking', function () {
        $s = AccessScenario::create();

        expect(visibleIds($s->admin))->toBe([$s->own->id, $s->other->id, $s->annaYara->id]);
    });

    it('shows nothing to an employee login without an employee profile', function () {
        AccessScenario::create();

        expect(visibleIds(User::factory()->employee()->create()))->toBe([]);
    });

    it('shows nothing to a new customer', function () {
        AccessScenario::create();

        expect(visibleIds(User::factory()->customer()->create()))->toBe([]);
    });
});

describe('BookingPolicy', function () {
    it('agrees with visibleTo for every user and booking', function () {
        $s = AccessScenario::create();
        $users = [$s->anna, $s->bram, $s->xavier->user, $s->yara->user, $s->admin];

        foreach ($users as $user) {
            $visible = visibleIds($user);

            foreach (Booking::all() as $booking) {
                expect(Gate::forUser($user)->allows('view', $booking))
                    ->toBe(in_array($booking->id, $visible, true), "user {$user->id}, booking {$booking->id}");
            }
        }
    });

    it('lets every role open the bookings list', function () {
        $s = AccessScenario::create();

        foreach ([$s->anna, $s->xavier->user, $s->admin] as $user) {
            expect(Gate::forUser($user)->allows('viewAny', Booking::class))->toBeTrue();
        }
    });
});

describe('bookings pages', function () {
    it('sends a guest to the login page', function () {
        $s = AccessScenario::create();

        get(route('bookings.index'))->assertRedirect(route('login'));
        get(route('bookings.show', $s->own))->assertRedirect(route('login'));
    });

    it('lists only the customer\'s own bookings', function () {
        $s = AccessScenario::create();

        actingAs($s->anna)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('Mijn boekingen')
            ->assertSee(bookingLink($s->own), false)
            ->assertSee(bookingLink($s->annaYara), false)
            ->assertDontSee(bookingLink($s->other), false);
    });

    it('lists only the employee\'s own agenda bookings', function () {
        $s = AccessScenario::create();

        actingAs($s->xavier->user)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee(bookingLink($s->own), false)
            ->assertDontSee(bookingLink($s->other), false)
            ->assertDontSee(bookingLink($s->annaYara), false);
    });

    it('lists every booking for an admin', function () {
        $s = AccessScenario::create();

        actingAs($s->admin)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee(bookingLink($s->own), false)
            ->assertSee(bookingLink($s->other), false)
            ->assertSee(bookingLink($s->annaYara), false);
    });

    it('shows a customer their own booking', function () {
        $s = AccessScenario::create();

        actingAs($s->anna)->get(route('bookings.show', $s->own))->assertOk();
    });

    it('hides someone else\'s booking from a customer as not found', function () {
        $s = AccessScenario::create();

        actingAs($s->anna)->get(route('bookings.show', $s->other))->assertNotFound();
    });

    it('shows an employee a booking in their agenda only', function () {
        $s = AccessScenario::create();

        actingAs($s->xavier->user)->get(route('bookings.show', $s->own))->assertOk();
        actingAs($s->xavier->user)->get(route('bookings.show', $s->annaYara))->assertNotFound();
    });

    it('shows an admin any booking', function () {
        $s = AccessScenario::create();

        foreach ([$s->own, $s->other, $s->annaYara] as $booking) {
            actingAs($s->admin)->get(route('bookings.show', $booking))->assertOk();
        }
    });

    it('answers not found for a booking id that does not exist', function () {
        $s = AccessScenario::create();

        actingAs($s->anna)->get(route('bookings.show', 999))->assertNotFound();
    });
});

describe('agenda', function () {
    it('sends an employee to their own agenda', function () {
        $s = AccessScenario::create();

        actingAs($s->xavier->user)
            ->get(route('agenda'))
            ->assertRedirect(route('employees.agenda', $s->xavier));
    });

    it('shows an employee their own agenda', function () {
        $s = AccessScenario::create();

        actingAs($s->xavier->user)
            ->get(agendaUrl($s->xavier))
            ->assertOk()
            ->assertSee(bookingLink($s->own), false)
            ->assertDontSee(bookingLink($s->other), false);
    });

    it('hides another employee\'s agenda as not found', function () {
        $s = AccessScenario::create();

        actingAs($s->xavier->user)
            ->get(route('employees.agenda', $s->yara))
            ->assertNotFound();
    });

    it('gives an employee login without a profile no agenda', function () {
        actingAs(User::factory()->employee()->create())
            ->get(route('agenda'))
            ->assertNotFound();
    });

    it('does not give customers an agenda', function () {
        $s = AccessScenario::create();

        actingAs($s->anna)->get(route('agenda'))->assertForbidden();
        actingAs($s->anna)->get(route('employees.agenda', $s->xavier))->assertForbidden();
    });

    it('shows an admin every agenda', function () {
        $s = AccessScenario::create();

        actingAs($s->admin)
            ->get(agendaUrl($s->yara))
            ->assertOk()
            ->assertSee(bookingLink($s->other), false)
            ->assertSee(bookingLink($s->annaYara), false)
            ->assertDontSee(bookingLink($s->own), false);
    });
});

describe('admin area', function () {
    it('is only open to admins', function () {
        $s = AccessScenario::create();

        actingAs($s->anna)->get(route('admin.dashboard'))->assertForbidden();
        actingAs($s->xavier->user)->get(route('admin.dashboard'))->assertForbidden();
        actingAs($s->admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Beheer');
    });

    it('sends a guest to the login page', function () {
        get(route('admin.dashboard'))->assertRedirect(route('login'));
    });
});

describe('navigation', function () {
    it('shows each role only its own links', function () {
        $s = AccessScenario::create();

        actingAs($s->anna)->get(route('dashboard'))
            ->assertSee('Mijn boekingen')
            ->assertDontSee('Mijn agenda')
            ->assertDontSee(route('admin.dashboard'));

        actingAs($s->xavier->user)->get(route('dashboard'))
            ->assertSee('Mijn agenda')
            ->assertDontSee(route('admin.dashboard'));

        actingAs($s->admin)->get(route('dashboard'))
            ->assertSee(route('admin.dashboard'))
            ->assertDontSee('Mijn agenda');
    });
});
