<?php

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('shows the login form', function () {
    get(route('login'))
        ->assertOk()
        ->assertSee('Wachtwoord vergeten?');
});

it('logs in with valid credentials', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);

    post(route('login'), ['email' => 'jan@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($user);
});

it('accepts the email address in any case', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);

    post(route('login'), ['email' => ' JAN@Example.com', 'password' => 'password']);

    assertAuthenticatedAs($user);
});

it('sends the user back to the page they wanted to visit', function () {
    User::factory()->create(['email' => 'jan@example.com']);

    get(route('dashboard'))->assertRedirect(route('login'));

    post(route('login'), ['email' => 'jan@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
});

it('sets a remember-me cookie when asked', function () {
    $user = User::factory()->create();

    post(route('login'), ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
        ->assertCookie(auth()->guard('web')->getRecallerName());
});

it('rejects a wrong password with a generic message', function () {
    User::factory()->create(['email' => 'jan@example.com']);

    from(route('login'))
        ->post(route('login'), ['email' => 'jan@example.com', 'password' => 'fout'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    assertGuest();
});

it('gives the same message for an unknown email address', function () {
    post(route('login'), ['email' => 'onbekend@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
});

it('validates the login input', function (array $data, string $field) {
    post(route('login'), $data)->assertSessionHasErrors($field);
    assertGuest();
})->with([
    'email missing' => [['email' => '', 'password' => 'password'], 'email'],
    'email invalid' => [['email' => 'geen-email', 'password' => 'password'], 'email'],
    'password missing' => [['email' => 'jan@example.com', 'password' => ''], 'password'],
]);

it('locks out after too many failed attempts', function () {
    Event::fake([Lockout::class]);
    User::factory()->create(['email' => 'jan@example.com']);

    for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
        post(route('login'), ['email' => 'jan@example.com', 'password' => 'fout']);
    }

    // Even the correct password is refused while locked out.
    post(route('login'), ['email' => 'jan@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    assertGuest();
    Event::assertDispatched(Lockout::class);
});

it('regenerates the session on login', function () {
    $user = User::factory()->create();

    get(route('login'));
    $before = session()->getId();

    post(route('login'), ['email' => $user->email, 'password' => 'password']);

    expect(session()->getId())->not->toBe($before);
});

it('logs out', function () {
    actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect('/');

    assertGuest();
});

it('shows the dashboard only to logged in users', function () {
    get(route('dashboard'))->assertRedirect(route('login'));

    actingAs(User::factory()->create(['name' => 'Jan Jansen']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Jan Jansen');
});

it('redirects logged in users away from the login form', function () {
    actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});
