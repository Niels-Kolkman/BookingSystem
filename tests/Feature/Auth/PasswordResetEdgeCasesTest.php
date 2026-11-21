<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('prefills the email address and token on the reset form', function () {
    get(route('password.reset', ['token' => 'abc123', 'email' => 'jan@example.com']))
        ->assertOk()
        ->assertSee('value="abc123"', false)
        ->assertSee('value="jan@example.com"', false);
});

it('escapes the email address from the query string on the reset form', function () {
    get(route('password.reset', ['token' => 'abc', 'email' => '"><script>alert(1)</script>']))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('requires an email address on the forgot password form', function () {
    Notification::fake();

    post(route('password.email'), ['email' => ''])
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
});

it('does not send a second reset mail right after the first one', function () {
    Notification::fake();
    $user = User::factory()->create();

    post(route('password.email'), ['email' => $user->email]);

    // The broker throttles, but the user sees the same message.
    post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('passwords.sent'));

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

it('rate limits the forgot password form', function () {
    for ($i = 0; $i < 6; $i++) {
        post(route('password.email'), ['email' => 'onbekend@example.com']);
    }

    post(route('password.email'), ['email' => 'onbekend@example.com'])->assertTooManyRequests();
});

it('rate limits the reset form', function () {
    for ($i = 0; $i < 6; $i++) {
        post(route('password.store'), ['token' => 'x', 'email' => 'jan@example.com', 'password' => 'a']);
    }

    post(route('password.store'), ['token' => 'x', 'email' => 'jan@example.com', 'password' => 'a'])
        ->assertTooManyRequests();
});

it('accepts the email address in any case when resetting', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);

    post(route('password.store'), [
        'token' => Password::createToken($user),
        'email' => ' Jan@Example.COM ',
        'password' => 'nieuwwachtwoord1',
        'password_confirmation' => 'nieuwwachtwoord1',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('nieuwwachtwoord1', $user->fresh()->password))->toBeTrue();
});

it('keeps the email address but not the password after a failed reset', function () {
    $user = User::factory()->create();

    from(route('password.reset', ['token' => 'ongeldig']))
        ->post(route('password.store'), [
            'token' => 'ongeldig',
            'email' => $user->email,
            'password' => 'nieuwwachtwoord1',
            'password_confirmation' => 'nieuwwachtwoord1',
        ])
        ->assertRedirect(route('password.reset', ['token' => 'ongeldig']))
        ->assertSessionHasInput('email', $user->email)
        ->assertSessionMissing('_old_input.password');
});

it('shows the same error for an unknown email address as for a bad token', function () {
    post(route('password.store'), [
        'token' => 'ongeldig',
        'email' => 'onbekend@example.com',
        'password' => 'nieuwwachtwoord1',
        'password_confirmation' => 'nieuwwachtwoord1',
    ])->assertSessionHasErrors(['email' => __('passwords.user')]);

    app()->setLocale('nl');
    expect(__('passwords.user'))->toBe(__('passwords.token'));
});

it('shows reset messages in Dutch', function () {
    app()->setLocale('nl');
    $user = User::factory()->create();

    post(route('password.store'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'nieuwwachtwoord1',
        'password_confirmation' => 'nieuwwachtwoord1',
    ])->assertSessionHas('status', 'Je wachtwoord is gewijzigd. Je kunt nu inloggen met je nieuwe wachtwoord.');
});

it('shows the status message on the page after redirecting', function () {
    post(route('password.email'), ['email' => 'jan@example.com']);

    get(route('password.request'))->assertSee(__('passwords.sent'));
});

it('only shows the password pages to guests', function (string $route, array $params) {
    actingAs(User::factory()->create())
        ->get(route($route, $params))
        ->assertRedirect(route('dashboard'));
})->with([
    'forgot password' => ['password.request', []],
    'reset password' => ['password.reset', ['token' => 'abc']],
]);
