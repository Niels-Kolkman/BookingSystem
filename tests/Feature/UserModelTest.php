<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has role and phone columns on the users table', function () {
    expect(Schema::hasColumns('users', ['role', 'phone']))->toBeTrue();
});

it('defaults the role to customer in the database', function () {
    DB::table('users')->insert([
        'name' => 'Jan',
        'email' => 'jan@example.com',
        'password' => 'x',
    ]);

    expect(DB::table('users')->value('role'))->toBe('customer')
        ->and(DB::table('users')->value('phone'))->toBeNull();
});

it('defaults the role to customer on a new model', function () {
    $user = new User;

    expect($user->role)->toBe(UserRole::Customer)
        ->and($user->isCustomer())->toBeTrue();
});

it('does not allow mass assigning the role', function () {
    $user = User::create([
        'name' => 'Jan',
        'email' => 'jan@example.com',
        'password' => 'geheim123',
        'role' => 'admin',
    ]);

    expect($user->fresh()->role)->toBe(UserRole::Customer);
});

it('stores and casts every role', function (UserRole $role) {
    $user = User::factory()->create();
    $user->role = $role;
    $user->save();

    expect($user->fresh()->role)->toBe($role)
        ->and($user->fresh()->isCustomer())->toBe($role === UserRole::Customer);
})->with(UserRole::cases());

it('has the roles from the ERD', function () {
    expect(array_map(fn (UserRole $role) => $role->value, UserRole::cases()))
        ->toBe(['customer', 'employee', 'admin']);
});

it('hashes the password', function () {
    $user = User::create(['name' => 'Jan', 'email' => 'jan@example.com', 'password' => 'geheim123']);

    expect($user->password)->not->toBe('geheim123');
});

it('hides the password and remember token when serialised', function () {
    $data = User::factory()->create(['phone' => '0612345678'])->toArray();

    expect($data)->not->toHaveKeys(['password', 'remember_token'])
        ->and($data)->toHaveKey('phone', '0612345678');
});

it('can roll back the role and phone migration', function () {
    $migration = require database_path('migrations/2026_11_21_000000_add_role_and_phone_to_users_table.php');

    $migration->down();
    expect(Schema::hasColumn('users', 'role'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'phone'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumns('users', ['role', 'phone']))->toBeTrue();
});
