<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityRegistration>
 */
class ActivityRegistrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'user_id' => User::factory(),
            'status' => 'registered',
            'registered_at' => now(),
            'attended' => false,
        ];
    }
}
