<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Knippen', 'Kleuren', 'Föhnen', 'Baard trimmen']),
            'description' => null,
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price_cents' => 2500,
            'is_active' => true,
        ];
    }
}
