<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\Meal;
use App\Models\MealOption;
use App\Services\LunchCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopyMealToCatererTest extends TestCase
{
    use RefreshDatabase;

    public function test_copying_a_meal_creates_a_caterer_meal_with_its_options(): void
    {
        $beef = MealOption::factory()->create(['name' => 'Beef']);
        $vegan = MealOption::factory()->create(['name' => 'Vegan']);
        $meal = Meal::factory()->create(['name' => 'Nasi Lemak', 'base_price' => 10.50]);
        $meal->options()->attach([$beef->id, $vegan->id]);
        $caterer = Caterer::factory()->create();

        $catererMeal = app(LunchCatalogService::class)->copyMealToCaterer($meal, $caterer);

        $this->assertSame('Nasi Lemak', $catererMeal->name);
        $this->assertEquals(10.50, $catererMeal->price);
        $this->assertSame($caterer->id, $catererMeal->caterer_id);
        $this->assertSame($meal->id, $catererMeal->meal_id);
        $this->assertSame(2, $catererMeal->options()->count());
        $this->assertNull($catererMeal->options()->first()->price);
        $this->assertTrue($catererMeal->meal->is($meal)); // source meal relation resolves
    }
}
