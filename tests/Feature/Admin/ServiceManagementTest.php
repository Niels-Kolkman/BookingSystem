<?php

use App\Enums\UserRole;
use App\Http\Requests\Admin\ServiceRequest;
use App\Models\Booking;
use App\Models\Service;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function serviceInput(array $overrides = []): array
{
    return array_merge([
        'name' => 'Knippen',
        'description' => 'Wassen, knippen en drogen.',
        'duration_minutes' => '30',
        'buffer_minutes' => '10',
        'price' => '25,50',
        'is_active' => '1',
    ], $overrides);
}

function admin(): User
{
    return User::factory()->admin()->create();
}

describe('access', function () {
    it('only lets admins manage services', function (UserRole $role, int $status) {
        $service = Service::factory()->create();
        $user = User::factory()->withRole($role)->create();
        $writeStatus = $status === 200 ? 302 : $status;

        actingAs($user)->get(route('admin.services.index'))->assertStatus($status);
        actingAs($user)->get(route('admin.services.create'))->assertStatus($status);
        actingAs($user)->get(route('admin.services.edit', $service))->assertStatus($status);
        actingAs($user)->post(route('admin.services.store'), serviceInput(['name' => 'Nieuw']))->assertStatus($writeStatus);
        actingAs($user)->put(route('admin.services.update', $service), serviceInput())->assertStatus($writeStatus);
    })->with([
        'customer' => [UserRole::Customer, 403],
        'employee' => [UserRole::Employee, 403],
        'admin' => [UserRole::Admin, 200],
    ]);

    it('does not let a non-admin delete a service', function () {
        $service = Service::factory()->create();

        actingAs(User::factory()->withRole(UserRole::Employee)->create())
            ->delete(route('admin.services.destroy', $service))
            ->assertForbidden();

        expect(Service::find($service->id))->not->toBeNull();
    });

    it('sends guests to the login page', function () {
        get(route('admin.services.index'))->assertRedirect(route('login'));
    });

    it('links to services from the admin dashboard', function () {
        actingAs(admin())->get(route('admin.dashboard'))
            ->assertSee(route('admin.services.index'));
    });
});

describe('index', function () {
    it('lists services with duration, buffer and price, active ones first', function () {
        Service::factory()->create(['name' => 'Baard trimmen', 'is_active' => false]);
        Service::factory()->create([
            'name' => 'Kleuren', 'duration_minutes' => 90, 'buffer_minutes' => 15, 'price_cents' => 123456,
        ]);

        actingAs(admin())->get(route('admin.services.index'))
            ->assertOk()
            ->assertSeeInOrder(['Kleuren', '90 min', '+ 15 min buffer', '€ 1.234,56', 'Baard trimmen', 'inactief']);
    });

    it('shows an empty state', function () {
        actingAs(admin())->get(route('admin.services.index'))
            ->assertOk()
            ->assertSee('Er zijn nog geen diensten.');
    });

    it('only offers deleting for services without bookings', function () {
        $free = Service::factory()->create(['name' => 'Föhnen']);
        $booked = Service::factory()->create(['name' => 'Knippen']);
        Booking::factory()->for($booked)->create();

        actingAs(admin())->get(route('admin.services.index'))
            ->assertSee(route('admin.services.destroy', $free))
            ->assertDontSee(route('admin.services.destroy', $booked));
    });
});

describe('create', function () {
    it('shows the form with sensible defaults', function () {
        actingAs(admin())->get(route('admin.services.create'))
            ->assertOk()
            ->assertSee('name="buffer_minutes"', false)
            ->assertSee('value="0"', false)
            ->assertSee('name="is_active" value="1" checked', false);
    });

    it('stores a service with the price in cents', function () {
        actingAs(admin())
            ->post(route('admin.services.store'), serviceInput())
            ->assertRedirect(route('admin.services.index'))
            ->assertSessionHas('status', 'Dienst "Knippen" is aangemaakt.');

        $service = Service::sole();

        expect($service->name)->toBe('Knippen')
            ->and($service->description)->toBe('Wassen, knippen en drogen.')
            ->and($service->duration_minutes)->toBe(30)
            ->and($service->buffer_minutes)->toBe(10)
            ->and($service->price_cents)->toBe(2550)
            ->and($service->is_active)->toBeTrue()
            ->and($service->blockedMinutes())->toBe(40);
    });

    it('stores an inactive service when the checkbox is not sent', function () {
        $input = serviceInput();
        unset($input['is_active']);

        actingAs(admin())->post(route('admin.services.store'), $input)->assertRedirect();

        expect(Service::sole()->is_active)->toBeFalse();
    });

    it('trims the name and stores an empty description as null', function () {
        actingAs(admin())
            ->post(route('admin.services.store'), serviceInput(['name' => '  Knippen  ', 'description' => '   ']))
            ->assertRedirect();

        expect(Service::sole())
            ->name->toBe('Knippen')
            ->description->toBeNull();
    });

    it('converts euro amounts to cents without rounding errors', function (string $price, int $cents) {
        actingAs(admin())->post(route('admin.services.store'), serviceInput(['price' => $price]))
            ->assertSessionHasNoErrors();

        expect(Service::sole()->price_cents)->toBe($cents);
    })->with([
        ['25', 2500],
        ['25,5', 2550],
        ['25.50', 2550],
        ['0,29', 29],
        ['19,99', 1999],
        ['0', 0],
        ['9999,99', 999999],
        [' 12,30 ', 1230],
    ]);

    it('rejects invalid prices', function (string $price) {
        actingAs(admin())
            ->from(route('admin.services.create'))
            ->post(route('admin.services.store'), serviceInput(['price' => $price]))
            ->assertRedirect(route('admin.services.create'))
            ->assertSessionHasErrors('price');

        expect(Service::count())->toBe(0);
    })->with(['', 'gratis', '-5', '25,555', '1.000,00', '10000', '€ 25']);

    it('explains the expected price format', function () {
        actingAs(admin())
            ->post(route('admin.services.store'), serviceInput(['price' => 'gratis']))
            ->assertSessionHasErrors(['price' => 'Vul de prijs in euro\'s in, bijvoorbeeld 25 of 25,50 (maximaal 9999,99).']);
    });

    it('validates duration, buffer, name and description', function (array $input, string $field) {
        actingAs(admin())
            ->post(route('admin.services.store'), serviceInput($input))
            ->assertSessionHasErrors($field);

        expect(Service::count())->toBe(0);
    })->with([
        'duration missing' => [['duration_minutes' => ''], 'duration_minutes'],
        'duration too short' => [['duration_minutes' => '4'], 'duration_minutes'],
        'duration too long' => [['duration_minutes' => (string) (ServiceRequest::MAX_DURATION_MINUTES + 1)], 'duration_minutes'],
        'duration not a whole number' => [['duration_minutes' => '30.5'], 'duration_minutes'],
        'buffer missing' => [['buffer_minutes' => ''], 'buffer_minutes'],
        'buffer negative' => [['buffer_minutes' => '-1'], 'buffer_minutes'],
        'buffer too long' => [['buffer_minutes' => (string) (ServiceRequest::MAX_BUFFER_MINUTES + 1)], 'buffer_minutes'],
        'name missing' => [['name' => '   '], 'name'],
        'name too long' => [['name' => str_repeat('a', 101)], 'name'],
        'description too long' => [['description' => str_repeat('a', 1001)], 'description'],
    ]);

    it('accepts the boundary values for duration and buffer', function () {
        actingAs(admin())
            ->post(route('admin.services.store'), serviceInput([
                'duration_minutes' => (string) ServiceRequest::MAX_DURATION_MINUTES,
                'buffer_minutes' => '0',
            ]))
            ->assertSessionHasNoErrors();

        expect(Service::sole())
            ->duration_minutes->toBe(ServiceRequest::MAX_DURATION_MINUTES)
            ->buffer_minutes->toBe(0);
    });

    it('shows validation messages in Dutch', function () {
        actingAs(admin())
            ->post(route('admin.services.store'), serviceInput(['duration_minutes' => '']))
            ->assertSessionHasErrors(['duration_minutes' => 'Duur is verplicht.']);
    });

    it('rejects a duplicate name', function () {
        Service::factory()->create(['name' => 'Knippen']);

        actingAs(admin())
            ->post(route('admin.services.store'), serviceInput(['name' => 'Knippen']))
            ->assertSessionHasErrors('name');

        expect(Service::count())->toBe(1);
    });

    it('ignores fields that are not part of the form', function () {
        actingAs(admin())
            ->post(route('admin.services.store'), serviceInput(['id' => 999, 'price_cents' => 1]))
            ->assertRedirect();

        expect(Service::sole())
            ->id->not->toBe(999)
            ->price_cents->toBe(2550);
    });
});

describe('edit', function () {
    it('shows the current values with the price in euros', function () {
        $service = Service::factory()->create([
            'name' => 'Kleuren', 'duration_minutes' => 90, 'buffer_minutes' => 15, 'price_cents' => 4505,
        ]);

        actingAs(admin())->get(route('admin.services.edit', $service))
            ->assertOk()
            ->assertSee('value="Kleuren"', false)
            ->assertSee('value="90"', false)
            ->assertSee('value="15"', false)
            ->assertSee('value="45,05"', false);
    });

    it('updates a service', function () {
        $service = Service::factory()->create(['name' => 'Knippen']);

        actingAs(admin())
            ->put(route('admin.services.update', $service), serviceInput([
                'name' => 'Knippen kort', 'duration_minutes' => '45', 'buffer_minutes' => '5', 'price' => '30',
            ]))
            ->assertRedirect(route('admin.services.index'))
            ->assertSessionHas('status', 'Dienst "Knippen kort" is opgeslagen.');

        expect($service->fresh())
            ->name->toBe('Knippen kort')
            ->duration_minutes->toBe(45)
            ->buffer_minutes->toBe(5)
            ->price_cents->toBe(3000);
    });

    it('can deactivate a service', function () {
        $service = Service::factory()->create(['is_active' => true]);
        $input = serviceInput(['name' => $service->name]);
        unset($input['is_active']);

        actingAs(admin())->put(route('admin.services.update', $service), $input)->assertRedirect();

        expect($service->fresh()->is_active)->toBeFalse()
            ->and(Service::active()->count())->toBe(0);
    });

    it('allows keeping the same name but not taking another service\'s name', function () {
        $service = Service::factory()->create(['name' => 'Knippen']);
        Service::factory()->create(['name' => 'Kleuren']);

        actingAs(admin())
            ->put(route('admin.services.update', $service), serviceInput(['name' => 'Knippen']))
            ->assertSessionHasNoErrors();

        actingAs(admin())
            ->put(route('admin.services.update', $service), serviceInput(['name' => 'Kleuren']))
            ->assertSessionHasErrors('name');
    });

    it('leaves the duration and price snapshot of existing bookings alone', function () {
        $service = Service::factory()->create(['duration_minutes' => 30, 'price_cents' => 2500]);
        $booking = Booking::factory()->for($service)->create();

        actingAs(admin())
            ->put(route('admin.services.update', $service), serviceInput([
                'name' => $service->name, 'duration_minutes' => '60', 'price' => '50',
            ]))
            ->assertRedirect();

        expect($booking->fresh())
            ->duration_minutes->toBe(30)
            ->price_cents->toBe(2500);
    });

    it('returns 404 for an unknown service', function () {
        actingAs(admin())->get(route('admin.services.edit', 999))->assertNotFound();
    });
});

describe('delete', function () {
    it('deletes a service without bookings', function () {
        $service = Service::factory()->create(['name' => 'Föhnen']);

        actingAs(admin())
            ->delete(route('admin.services.destroy', $service))
            ->assertRedirect(route('admin.services.index'))
            ->assertSessionHas('status', 'Dienst "Föhnen" is verwijderd.');

        expect(Service::find($service->id))->toBeNull();
    });

    it('refuses to delete a service with bookings and suggests deactivating it', function () {
        $service = Service::factory()->create(['name' => 'Knippen']);
        Booking::factory()->for($service)->create();

        actingAs(admin())
            ->followingRedirects()
            ->delete(route('admin.services.destroy', $service))
            ->assertOk()
            ->assertSee('heeft boekingen en kan niet worden verwijderd')
            ->assertSee('role="alert"', false);

        expect(Service::find($service->id))->not->toBeNull();
    });
});

it('formats prices the Dutch way', function (int $cents, string $expected) {
    expect(Service::factory()->make(['price_cents' => $cents])->formattedPrice())->toBe($expected);
})->with([
    [0, '€ 0,00'],
    [5, '€ 0,05'],
    [2550, '€ 25,50'],
    [123456, '€ 1.234,56'],
]);
