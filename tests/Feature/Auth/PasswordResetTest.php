<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;

it('shows the forgot password form', function () {
    get(route('password.request'))->assertOk();
});

it('sends a reset link to an existing user', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'jan@example.com']);

    post(route('password.email'), ['email' => 'Jan@Example.com'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('passwords.sent'));

    Notification::assertSentTo($user, ResetPassword::class);
});

it('does not reveal whether an email address has an account', function () {
    Notification::fake();

    post(route('password.email'), ['email' => 'onbekend@example.com'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('passwords.sent'));

    Notification::assertNothingSent();
});

it('sends the reset mail in Dutch', function () {
    app()->setLocale('nl');
    $user = User::factory()->create();
    $mail = (new ResetPassword('token'))->toMail($user);

    expect($mail->subject)->toBe('Wachtwoord opnieuw instellen')
        ->and($mail->actionText)->toBe('Nieuw wachtwoord instellen');
});

it('only overrides the changed English password messages', function () {
    app()->setLocale('en');

    expect(__('passwords.sent'))->toStartWith('If an account exists')
        // Still provided by the framework, so the override file is merged, not replacing.
        ->and(__('passwords.token'))->toBe('This password reset token is invalid.');
});

it('validates the email address on the forgot password form', function () {
    post(route('password.email'), ['email' => 'geen-email'])
        ->assertSessionHasErrors('email');
});

it('builds a reset link that opens the reset form', function () {
    Notification::fake();
    $user = User::factory()->create();

    post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        expect($url)->toContain('/reset-password/'.$notification->token);

        get($url)
            ->assertOk()
            ->assertSee($user->email);

        return true;
    });
});

it('resets the password with a valid token', function () {
    Event::fake([PasswordReset::class]);
    $user = User::factory()->create(['email' => 'jan@example.com']);
    $oldRememberToken = $user->remember_token;
    $token = Password::createToken($user);

    post(route('password.store'), [
        'token' => $token,
        'email' => 'jan@example.com',
        'password' => 'nieuwwachtwoord1',
        'password_confirmation' => 'nieuwwachtwoord1',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('passwords.reset'));

    $user->refresh();
    expect(Hash::check('nieuwwachtwoord1', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe($oldRememberToken);

    Event::assertDispatched(PasswordReset::class);

    // The token can only be used once.
    post(route('password.store'), [
        'token' => $token,
        'email' => 'jan@example.com',
        'password' => 'nogeenkeer1',
        'password_confirmation' => 'nogeenkeer1',
    ])->assertSessionHasErrors('email');
});

it('can log in with the new password after a reset', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);

    post(route('password.store'), [
        'token' => Password::createToken($user),
        'email' => 'jan@example.com',
        'password' => 'nieuwwachtwoord1',
        'password_confirmation' => 'nieuwwachtwoord1',
    ]);

    post(route('login'), ['email' => 'jan@example.com', 'password' => 'nieuwwachtwoord1']);

    assertAuthenticatedAs($user);
});

it('rejects an invalid token', function () {
    $user = User::factory()->create();

    post(route('password.store'), [
        'token' => 'ongeldig',
        'email' => $user->email,
        'password' => 'nieuwwachtwoord1',
        'password_confirmation' => 'nieuwwachtwoord1',
    ])->assertSessionHasErrors(['email' => __('passwords.token')]);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('rejects an expired token', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    travel(config('auth.passwords.users.expire') + 1)->minutes();

    post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'nieuwwachtwoord1',
        'password_confirmation' => 'nieuwwachtwoord1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('does not accept a token for a different user', function () {
    $jan = User::factory()->create();
    $piet = User::factory()->create();

    post(route('password.store'), [
        'token' => Password::createToken($jan),
        'email' => $piet->email,
        'password' => 'nieuwwachtwoord1',
        'password_confirmation' => 'nieuwwachtwoord1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $piet->fresh()->password))->toBeTrue();
});

it('validates the new password', function (array $overrides, string $field) {
    $user = User::factory()->create();

    post(route('password.store'), array_merge([
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'nieuwwachtwoord1',
        'password_confirmation' => 'nieuwwachtwoord1',
    ], $overrides))->assertSessionHasErrors($field);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
})->with([
    'too short' => [['password' => 'abc1', 'password_confirmation' => 'abc1'], 'password'],
    'no number' => [['password' => 'alleenletters', 'password_confirmation' => 'alleenletters'], 'password'],
    'not confirmed' => [['password_confirmation' => 'iets-anders1'], 'password'],
    'token missing' => [['token' => ''], 'token'],
]);
