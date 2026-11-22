<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\Employee;
use App\Models\WorkingHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkingHour>
 */
class WorkingHourFactory extends Factory
{
    /**
     * Define the model's default state: Monday 09:00-17:00.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'weekday' => Weekday::Monday,
            'starts_at' => '09:00',
            'ends_at' => '17:00',
        ];
    }

    public function on(Weekday $weekday, string $startsAt, string $endsAt): static
    {
        return $this->state(fn (array $attributes) => [
            'weekday' => $weekday,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
