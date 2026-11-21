<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;

beforeEach(fn () => app()->setLocale('nl'));

it('translates every auth message', function () {
    $framework = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/auth.php');

    foreach (array_keys($framework) as $key) {
        expect(trans()->hasForLocale("auth.$key", 'nl'))->toBeTrue("auth.$key is not translated");
    }
});

it('translates every password broker message', function () {
    $framework = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/passwords.php');

    foreach (array_keys($framework) as $key) {
        expect(trans()->hasForLocale("passwords.$key", 'nl'))->toBeTrue("passwords.$key is not translated");
    }
});

it('translates every validation rule used by the auth forms', function (string $key) {
    expect(trans()->hasForLocale("validation.$key", 'nl'))->toBeTrue();
})->with([
    'required', 'string', 'email', 'max.string', 'min.string', 'unique', 'confirmed', 'regex',
    'password.letters', 'password.numbers', 'password.uncompromised',
]);

it('translates the field names used in the auth forms', function (string $field, string $dutch) {
    expect(__("validation.attributes.$field"))->toBe($dutch);
})->with([
    ['name', 'naam'],
    ['email', 'e-mailadres'],
    ['phone', 'telefoonnummer'],
    ['password', 'wachtwoord'],
]);

it('renders the whole reset mail in Dutch', function () {
    $user = User::factory()->create();
    $html = (string) (new ResetPassword('token'))->toMail($user)->render();

    expect($html)
        ->toContain('Hallo!')
        ->toContain('Je ontvangt deze e-mail')
        ->toContain('Deze link is '.config('auth.passwords.users.expire').' minuten geldig.')
        ->toContain('Heb je dit niet zelf aangevraagd?')
        ->toContain('Met vriendelijke groet,')
        ->toContain('Alle rechten voorbehouden.')
        ->not->toContain('Regards')
        ->not->toContain('password reset request')
        ->not->toContain('All rights reserved');
});

it('falls back to English for a rule without a Dutch message', function () {
    // Documents the behaviour described in lang/nl/validation.php.
    expect(trans()->hasForLocale('validation.ulid', 'nl'))->toBeFalse()
        ->and(__('validation.ulid', ['attribute' => 'code']))->toContain('must be a valid ULID');
});
