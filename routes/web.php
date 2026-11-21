<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BookingController;
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
    Route::get('bookings/{booking}', [BookingController::class, 'show'])
        ->middleware('can:view,booking')
        ->name('bookings.show');

    Route::get('agenda', [AgendaController::class, 'mine'])
        ->middleware('role:employee')
        ->name('agenda');
    Route::get('employees/{employee}/agenda', [AgendaController::class, 'show'])
        ->middleware(['role:employee,admin', 'can:viewAgenda,employee'])
        ->name('employees.agenda');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::view('/', 'admin.dashboard')->name('dashboard');
    });
});
