<?php

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

it('links a booking to its customer, employee and service', function () {
    $booking = Booking::factory()->create();

    expect($booking->customer->role)->toBe(UserRole::Customer)
        ->and($booking->employee->user->role)->toBe(UserRole::Employee)
        ->and($booking->service)->not->toBeNull()
        ->and($booking->customer->bookings->pluck('id')->all())->toBe([$booking->id])
        ->and($booking->employee->bookings->pluck('id')->all())->toBe([$booking->id]);
});

it('casts status and times', function () {
    $booking = Booking::factory()->create()->fresh();

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->starts_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($booking->ends_at->diffInMinutes($booking->starts_at, true))->toEqual(30);
});

it('gives an employee user their profile', function () {
    $employee = Employee::factory()->create();

    expect($employee->user->employee->is($employee))->toBeTrue()
        ->and($employee->is_active)->toBeTrue();
});

it('allows only one employee profile per user', function () {
    $employee = Employee::factory()->create();

    $second = new Employee(['display_name' => 'Dubbel']);
    $second->user_id = $employee->user_id;
    $second->save();
})->throws(QueryException::class);

it('does not mass assign the user of an employee profile', function () {
    $user = User::factory()->employee()->create();

    $employee = new Employee(['user_id' => $user->id, 'display_name' => 'Sanne']);

    expect($employee->user_id)->toBeNull();
});

it('has the booking statuses from the ERD', function () {
    expect(array_map(fn (BookingStatus $status) => $status->value, BookingStatus::cases()))
        ->toBe(['requested', 'confirmed', 'cancelled', 'completed', 'no_show']);
});

it('has role helpers on the user', function (UserRole $role) {
    $user = User::factory()->withRole($role)->make();

    expect($user->isCustomer())->toBe($role === UserRole::Customer)
        ->and($user->isEmployee())->toBe($role === UserRole::Employee)
        ->and($user->isAdmin())->toBe($role === UserRole::Admin)
        ->and($user->hasRole($role))->toBeTrue()
        ->and($user->hasRole(...array_filter(UserRole::cases(), fn ($r) => $r !== $role)))->toBeFalse();
})->with(UserRole::cases());
