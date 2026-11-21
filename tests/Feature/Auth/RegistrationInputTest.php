<?php

use App\Models\User;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jan Jansen',
        'email' => 'jan@example.com',
        'phone' => '0612345678',
        'password' => 'geheim123',
        'password_confirmation' => 'geheim123',
    ], $overrides);
}

it('trims the name and phone number', function () {
    post(route('register'), validRegistration(['name' => '  Jan Jansen ', 'phone' => ' 06 12345678 ']))
        ->assertSessionHasNoErrors();

    $user = User::where('email', 'jan@example.com')->firstOrFail();

    expect($user->name)->toBe('Jan Jansen')
        ->and($user->phone)->toBe('06 12345678');
});

it('rejects a name of only spaces', function () {
    post(route('register'), validRegistration(['name' => '   ']))
        ->assertSessionHasErrors('name');
});

it('accepts common phone number formats', function (string $phone) {
    post(route('register'), validRegistration(['phone' => $phone]))
        ->assertSessionHasNoErrors();

    expect(User::where('email', 'jan@example.com')->value('phone'))->toBe($phone);
})->with([
    'mobile' => '0612345678',
    'international' => '+31 6 12345678',
    'landline with area code' => '(020) 123-4567',
]);

it('rejects invalid phone numbers', function (string $phone) {
    post(route('register'), validRegistration(['phone' => $phone]))
        ->assertSessionHasErrors('phone');

    expect(User::count())->toBe(0);
})->with([
    'too short' => '12345',
    'too long' => str_repeat('1', 31),
    'plus sign in the middle' => '06+12345678',
    'letters' => '06-ABCDEFGH',
]);

it('rejects an email address longer than 255 characters', function () {
    post(route('register'), validRegistration(['email' => str_repeat('a', 244).'@example.com']))
        ->assertSessionHasErrors('email');
});

it('accepts a password of exactly 8 characters', function () {
    post(route('register'), validRegistration(['password' => 'geheim12', 'password_confirmation' => 'geheim12']))
        ->assertSessionHasNoErrors();
});

it('regenerates the session on registration', function () {
    get(route('register'));
    $before = session()->getId();

    post(route('register'), validRegistration());

    expect(session()->getId())->not->toBe($before);
});

it('shows a friendly Dutch message when the email address is taken', function () {
    app()->setLocale('nl');
    User::factory()->create(['email' => 'jan@example.com']);

    post(route('register'), validRegistration())
        ->assertSessionHasErrors([
            'email' => 'Er bestaat al een account met dit e-mailadres. Wil je inloggen of je wachtwoord resetten?',
        ]);
});

it('uses Dutch field names in validation messages', function () {
    app()->setLocale('nl');

    post(route('register'), validRegistration(['name' => '', 'phone' => 'x', 'password_confirmation' => 'anders123']))
        ->assertSessionHasErrors([
            'name' => 'Naam is verplicht.',
            'phone' => 'Het formaat van telefoonnummer is ongeldig.',
            'password' => 'De bevestiging van wachtwoord komt niet overeen.',
        ]);
});

it('rate limits registration attempts', function () {
    for ($i = 0; $i < 10; $i++) {
        post(route('register'), validRegistration(['email' => 'geen-email']))
            ->assertStatus(302);
    }

    post(route('register'), validRegistration())->assertTooManyRequests();

    expect(User::count())->toBe(0);
});
