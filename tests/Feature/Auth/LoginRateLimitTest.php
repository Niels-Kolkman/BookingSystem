<?php

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\freezeSecond;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;
use function Pest\Laravel\withServerVariables;

function failLogin(string $email, int $times, string $ip = '127.0.0.1'): void
{
    for ($i = 0; $i < $times; $i++) {
        withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('login'), ['email' => $email, 'password' => 'fout']);
    }
}

it('still allows logging in just below the attempt limit', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);

    failLogin('jan@example.com', LoginRequest::MAX_ATTEMPTS - 1);

    post(route('login'), ['email' => 'jan@example.com', 'password' => 'password'])
        ->assertSessionHasNoErrors();

    assertAuthenticatedAs($user);
});

it('shows the lockout message in Dutch with the remaining seconds', function () {
    app()->setLocale('nl');
    freezeSecond();
    User::factory()->create(['email' => 'jan@example.com']);

    failLogin('jan@example.com', LoginRequest::MAX_ATTEMPTS);

    post(route('login'), ['email' => 'jan@example.com', 'password' => 'password'])
        ->assertSessionHasErrors([
            'email' => 'Te veel inlogpogingen. Probeer het over 60 seconden opnieuw.',
        ]);
});

it('counts attempts for differently cased email addresses together', function () {
    User::factory()->create(['email' => 'jan@example.com']);

    failLogin('JAN@example.com', LoginRequest::MAX_ATTEMPTS);

    post(route('login'), ['email' => 'jan@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    assertGuest();
});

it('only locks out the attacked email address', function () {
    User::factory()->create(['email' => 'jan@example.com']);
    $piet = User::factory()->create(['email' => 'piet@example.com']);

    failLogin('jan@example.com', LoginRequest::MAX_ATTEMPTS);

    post(route('login'), ['email' => 'piet@example.com', 'password' => 'password']);

    assertAuthenticatedAs($piet);
});

it('only locks out the IP address the attempts came from', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);

    failLogin('jan@example.com', LoginRequest::MAX_ATTEMPTS, '10.0.0.1');

    withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->post(route('login'), ['email' => 'jan@example.com', 'password' => 'password']);

    assertAuthenticatedAs($user);
});

it('lifts the lockout after the decay period', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);

    failLogin('jan@example.com', LoginRequest::MAX_ATTEMPTS);

    travel(61)->seconds();

    post(route('login'), ['email' => 'jan@example.com', 'password' => 'password']);

    assertAuthenticatedAs($user);
});

it('resets the failed attempt counter after a successful login', function () {
    User::factory()->create(['email' => 'jan@example.com']);

    failLogin('jan@example.com', LoginRequest::MAX_ATTEMPTS - 1);
    post(route('login'), ['email' => 'jan@example.com', 'password' => 'password']);
    post(route('logout'));

    // Without the reset, one more failure would lock the user out.
    failLogin('jan@example.com', LoginRequest::MAX_ATTEMPTS - 1);

    post(route('login'), ['email' => 'jan@example.com', 'password' => 'password'])
        ->assertSessionHasNoErrors();
});

it('does not count invalid input as a failed attempt', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);

    for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
        post(route('login'), ['email' => 'jan@example.com', 'password' => '']);
    }

    post(route('login'), ['email' => 'jan@example.com', 'password' => 'password']);

    assertAuthenticatedAs($user);
});

it('forgets session data on logout', function () {
    actingAs(User::factory()->create())
        ->withSession(['winkelmand' => 'iets'])
        ->post(route('logout'));

    expect(session()->has('winkelmand'))->toBeFalse();
});

it('only allows logging out with a POST request', function () {
    actingAs(User::factory()->create())
        ->get('/logout')
        ->assertMethodNotAllowed();
});

it('sends guests that try to log out to the login form', function () {
    post(route('logout'))->assertRedirect(route('login'));
});

it('shows a logout button only to logged in users', function () {
    get(route('login'))->assertDontSee('Uitloggen');

    actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee('Uitloggen');
});
