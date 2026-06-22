<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\CatererMealOption;
use App\Models\MealOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_option_price_falls_back_to_the_meal_base_price(): void
    {
        $meal = CatererMeal::create([
            'caterer_id' => Caterer::factory()->create()->id,
            'name' => 'Nasi Campur',
            'price' => 12.00,
        ]);
        $beef = MealOption::factory()->create(['name' => 'Beef']);
        $vegan = MealOption::factory()->create(['name' => 'Vegan']);

        $beefRow = CatererMealOption::create([
            'caterer_meal_id' => $meal->id, 'meal_option_id' => $beef->id, 'price' => 15.00,
        ]);
        $veganRow = CatererMealOption::create([
            'caterer_meal_id' => $meal->id, 'meal_option_id' => $vegan->id, 'price' => null,
        ]);

        $this->assertSame(15.0, $beefRow->effective_price);  // override wins
        $this->assertSame(12.0, $veganRow->effective_price); // falls back to the meal's base price
    }
}
