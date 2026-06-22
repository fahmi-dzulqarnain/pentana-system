<?php

namespace Database\Factories;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Lunch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lunch>
 */
class LunchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->date(),
            'caterer_id' => Caterer::factory(),
            'caterer_meal_id' => CatererMeal::factory(),
            'deadline' => now()->addDays(3),
            'status' => 'draft',
        ];
    }
}
