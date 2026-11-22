<?php

namespace App\Providers;

use App\Scheduling\AvailableSlots;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Slot calculation (ISSUE-11): inject AvailableSlots, the interval comes from config/booking.php.
        $this->app->bind(AvailableSlots::class, fn (): AvailableSlots => AvailableSlots::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Used by registration and password reset via Password::defaults().
        // The breach check calls an external API, so only run it in production.
        Password::defaults(function () {
            $rule = Password::min(8)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
