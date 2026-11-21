<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Times are UTC. Duration and price are a fixed snapshot so the factory
     * does not have to load the service.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now('UTC')->addDays(fake()->numberBetween(1, 14))->setTime(9, 0)->toImmutable();

        return [
            'customer_id' => User::factory()->customer(),
            'employee_id' => Employee::factory(),
            'service_id' => Service::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'blocked_until' => $startsAt->addMinutes(30),
            'duration_minutes' => 30,
            'price_cents' => 2500,
            'status' => BookingStatus::Confirmed,
        ];
    }
}
