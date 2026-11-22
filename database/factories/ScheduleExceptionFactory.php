<?php

namespace Database\Factories;

use App\Enums\ScheduleExceptionType;
use App\Models\Employee;
use App\Models\ScheduleException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleException>
 */
class ScheduleExceptionFactory extends Factory
{
    /**
     * Define the model's default state: a whole day off on 2026-12-01.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date' => '2026-12-01',
            'type' => ScheduleExceptionType::Unavailable,
            'starts_at' => null,
            'ends_at' => null,
            'reason' => null,
        ];
    }

    /**
     * Unavailable on a date: the whole day, or only between the given times.
     */
    public function unavailable(string $date, ?string $startsAt = null, ?string $endsAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
            'type' => ScheduleExceptionType::Unavailable,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    public function extraHours(string $date, string $startsAt, string $endsAt): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
            'type' => ScheduleExceptionType::ExtraHours,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
