<?php

namespace Database\Factories;

use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchMenu;
use App\Models\LunchOption;
use App\Models\MealOption;
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
            'deadline' => now()->addDays(3),
            'status' => 'draft',
        ];
    }

    /**
     * Add a menu (from $meal, or a fresh one) with one enabled option per given meal option.
     *
     * @param  array<int, MealOption>  $mealOptions
     */
    public function withMenu(?CatererMeal $meal = null, array $mealOptions = []): static
    {
        return $this->afterCreating(function (Lunch $lunch) use ($meal, $mealOptions) {
            $meal ??= CatererMeal::factory()->create();

            $menu = LunchMenu::create([
                'lunch_id' => $lunch->id,
                'caterer_id' => $meal->caterer_id,
                'caterer_meal_id' => $meal->id,
                'sort' => $lunch->menus()->count(),
            ]);

            foreach ($mealOptions as $mealOption) {
                LunchOption::create([
                    'lunch_id' => $lunch->id,
                    'lunch_menu_id' => $menu->id,
                    'meal_option_id' => $mealOption->id,
                    'enabled' => true,
                    'default_count' => 0,
                ]);
            }

            $lunch->unsetRelation('menus');
        });
    }
}
