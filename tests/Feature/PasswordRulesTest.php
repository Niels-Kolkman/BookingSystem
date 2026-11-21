<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

function pwnedResponseFor(string $password): string
{
    $hash = strtoupper(sha1($password));

    return substr($hash, 5).':12345';
}

it('does not call the breach check API outside production', function () {
    Http::fake();

    $validator = Validator::make(['password' => 'geheim123'], ['password' => Password::defaults()]);

    expect($validator->passes())->toBeTrue();
    Http::assertNothingSent();
});

it('rejects a leaked password in production', function () {
    app()->detectEnvironment(fn () => 'production');
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(pwnedResponseFor('geheim123'))]);

    $validator = Validator::make(['password' => 'geheim123'], ['password' => Password::defaults()]);

    expect($validator->fails())->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.pwnedpasswords.com/range/'));
});

it('accepts a password that is not leaked in production', function () {
    app()->detectEnvironment(fn () => 'production');
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(pwnedResponseFor('iets-anders1'))]);

    $validator = Validator::make(['password' => 'geheim123'], ['password' => Password::defaults()]);

    expect($validator->passes())->toBeTrue();
});

it('shows the Dutch leaked password message', function () {
    app()->setLocale('nl');
    app()->detectEnvironment(fn () => 'production');
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(pwnedResponseFor('geheim123'))]);

    $validator = Validator::make(['password' => 'geheim123'], ['password' => Password::defaults()]);

    expect($validator->errors()->first('password'))
        ->toBe('Dit wachtwoord komt voor in een datalek. Kies een ander wachtwoord.');
});
