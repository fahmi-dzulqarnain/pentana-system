<?php

namespace Database\Factories;

use App\Models\Caterer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Caterer>
 */
class CatererFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'notes' => null,
        ];
    }
}
