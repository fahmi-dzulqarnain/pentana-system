<?php

namespace Tests\Feature\Lunch;

use App\Models\Meal;
use App\Models\MealOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealOptionsRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_meal_can_have_options(): void
    {
        $meal = Meal::factory()->create();
        $beef = MealOption::factory()->create(['name' => 'Beef']);
        $meal->options()->attach($beef->id);

        $this->assertTrue($meal->options->contains($beef));
    }
}
