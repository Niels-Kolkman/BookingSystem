<?php

use App\Http\Controllers\Admin\EmployeeScheduleController;
use App\Http\Controllers\Admin\ScheduleExceptionController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingSlotController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:10,1');

    // Login attempts are rate limited per email + IP in LoginRequest.
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);

    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    // Route name 'password.reset' is used by the reset-link notification.
    Route::get('reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Authorization (ISSUE-6): the `role` middleware guards whole areas, the
    // policies decide per booking / per agenda. See BookingPolicy and
    // EmployeePolicy; lists are limited with Booking::visibleTo().
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    // Booking page (ISSUE-15): steps on bookings/create, free times live as
    // JSON from bookings/slots. Both before bookings/{booking}.
    Route::get('bookings/create', [BookingController::class, 'create'])
        ->middleware('role:customer')
        ->name('bookings.create');
    Route::get('bookings/slots', BookingSlotController::class)
        ->middleware(['role:customer', 'throttle:60,1'])
        ->name('bookings.slots');
    // Booking a free slot (ISSUE-12); overlap rules live in CreateBooking.
    Route::post('bookings', [BookingController::class, 'store'])
        ->middleware(['role:customer', 'throttle:30,1'])
        ->name('bookings.store');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])
        ->middleware('can:view,booking')
        ->name('bookings.show');
    // Cancelling (ISSUE-16): customer until the cancellation deadline,
    // admin always; the rules live in BookingPolicy::cancel().
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])
        ->middleware(['can:view,booking', 'throttle:30,1'])
        ->name('bookings.cancel');

    Route::get('agenda', [AgendaController::class, 'mine'])
        ->middleware('role:employee')
        ->name('agenda');
    Route::get('employees/{employee}/agenda', [AgendaController::class, 'show'])
        ->middleware(['role:employee,admin', 'can:viewAgenda,employee'])
        ->name('employees.agenda');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::view('/', 'admin.dashboard')->name('dashboard');

        Route::resource('services', ServiceController::class)->except('show');

        // Weekly schedules per employee (ISSUE-9).
        Route::get('employees', [EmployeeScheduleController::class, 'index'])->name('employees.index');
        Route::get('employees/{employee}/schedule', [EmployeeScheduleController::class, 'edit'])->name('employees.schedule.edit');
        Route::put('employees/{employee}/schedule', [EmployeeScheduleController::class, 'update'])->name('employees.schedule.update');

        // Schedule exceptions per employee: time off and extra hours (ISSUE-10).
        Route::get('employees/{employee}/exceptions', [ScheduleExceptionController::class, 'index'])->name('employees.exceptions.index');
        Route::post('employees/{employee}/exceptions', [ScheduleExceptionController::class, 'store'])->name('employees.exceptions.store');
        Route::delete('employees/{employee}/exceptions/{scheduleException}', [ScheduleExceptionController::class, 'destroy'])
            ->scopeBindings()
            ->name('employees.exceptions.destroy');
    });
});
