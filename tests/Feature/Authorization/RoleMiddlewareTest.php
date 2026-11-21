<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutExceptionHandling;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'role:admin'])->get('_test/admin-only', fn () => 'ok');
    Route::middleware(['web', 'auth', 'role:employee,admin'])->get('_test/staff', fn () => 'ok');
    Route::middleware(['web', 'role:admin'])->get('_test/no-auth', fn () => 'ok');
    Route::middleware(['web', 'auth', 'role:superuser'])->get('_test/typo', fn () => 'ok');
});

it('lets only the given role through', function (UserRole $role, int $status) {
    actingAs(User::factory()->withRole($role)->create())
        ->get('_test/admin-only')
        ->assertStatus($status);
})->with([
    'customer' => [UserRole::Customer, 403],
    'employee' => [UserRole::Employee, 403],
    'admin' => [UserRole::Admin, 200],
]);

it('accepts any of several roles', function (UserRole $role, int $status) {
    actingAs(User::factory()->withRole($role)->create())
        ->get('_test/staff')
        ->assertStatus($status);
})->with([
    'customer' => [UserRole::Customer, 403],
    'employee' => [UserRole::Employee, 200],
    'admin' => [UserRole::Admin, 200],
]);

it('denies a guest even without the auth middleware', function () {
    get('_test/no-auth')->assertForbidden();
});

it('fails loudly on an unknown role name instead of denying everyone', function () {
    withoutExceptionHandling();

    actingAs(User::factory()->admin()->create())->get('_test/typo');
})->throws(ValueError::class);
