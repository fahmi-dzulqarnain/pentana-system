<?php

namespace Database\Factories;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'starts_at' => now()->addWeek(),
            'location' => fake()->city(),
            'capacity' => null,
            'status' => 'draft',
            'registration_closes_at' => null,
            'ends_at' => null,
            'reminder_sent_at' => null,
        ];
    }
}
