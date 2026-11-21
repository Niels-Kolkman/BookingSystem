<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jan Jansen',
        'email' => 'jan@example.com',
        'phone' => '06 12345678',
        'password' => 'geheim123',
        'password_confirmation' => 'geheim123',
    ], $overrides);
}

it('shows the registration form', function () {
    get(route('register'))
        ->assertOk()
        ->assertSee('Account aanmaken');
});

it('registers a new customer and logs them in', function () {
    Event::fake([Registered::class]);

    post(route('register'), registrationData())
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'jan@example.com')->firstOrFail();

    expect($user->name)->toBe('Jan Jansen')
        ->and($user->phone)->toBe('06 12345678')
        ->and($user->role)->toBe(UserRole::Customer)
        ->and(Hash::check('geheim123', $user->password))->toBeTrue();

    assertAuthenticatedAs($user);
    Event::assertDispatched(Registered::class);
});

it('allows registering without a phone number', function () {
    post(route('register'), registrationData(['phone' => '']))
        ->assertRedirect(route('dashboard'));

    expect(User::where('email', 'jan@example.com')->value('phone'))->toBeNull();
});

it('normalises the email address', function () {
    post(route('register'), registrationData(['email' => '  Jan@Example.COM ']));

    expect(User::where('email', 'jan@example.com')->exists())->toBeTrue();
});

it('cannot register as employee or admin through the form', function () {
    post(route('register'), registrationData(['role' => 'admin']));

    expect(User::where('email', 'jan@example.com')->firstOrFail()->role)->toBe(UserRole::Customer);
});

it('rejects an email address that is already taken, regardless of case', function () {
    User::factory()->create(['email' => 'jan@example.com']);

    post(route('register'), registrationData(['email' => 'JAN@example.com']))
        ->assertSessionHasErrors('email');

    expect(User::count())->toBe(1);
    assertGuest();
});

it('validates the registration input', function (array $overrides, string $field) {
    from(route('register'))
        ->post(route('register'), registrationData($overrides))
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors($field);

    assertGuest();
    expect(User::count())->toBe(0);
})->with([
    'name missing' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'email missing' => [['email' => ''], 'email'],
    'email invalid' => [['email' => 'geen-email'], 'email'],
    'phone invalid' => [['phone' => 'bel me maar'], 'phone'],
    'password missing' => [['password' => '', 'password_confirmation' => ''], 'password'],
    'password too short' => [['password' => 'abc12', 'password_confirmation' => 'abc12'], 'password'],
    'password without numbers' => [['password' => 'geheimpje', 'password_confirmation' => 'geheimpje'], 'password'],
    'password without letters' => [['password' => '12345678', 'password_confirmation' => '12345678'], 'password'],
    'password not confirmed' => [['password_confirmation' => 'anders123'], 'password'],
]);

it('keeps the input but not the password after a validation error', function () {
    from(route('register'))
        ->post(route('register'), registrationData(['email' => 'geen-email']))
        ->assertSessionHasInput('name', 'Jan Jansen')
        ->assertSessionMissing('_old_input.password');
});

it('shows validation errors in Dutch', function () {
    app()->setLocale('nl');

    post(route('register'), registrationData(['email' => 'geen-email', 'password' => 'kort1', 'password_confirmation' => 'kort1']))
        ->assertSessionHasErrors([
            'email' => 'E-mailadres moet een geldig e-mailadres zijn.',
            'password' => 'Wachtwoord moet minimaal 8 tekens bevatten.',
        ]);
});

it('redirects logged in users away from the registration form', function () {
    actingAs(User::factory()->create())
        ->get(route('register'))
        ->assertRedirect(route('dashboard'));
});
