<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\Pages\EditLunch;
use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\CatererMealOption;
use App\Models\Lunch;
use App\Models\MealOption;
use App\Models\User;
use App\Services\LunchPlanningService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Production, 2026-10-16 15:38: saving a lunch again after changing its menu inserted
 * option rows with no meal option (NOT NULL violation, 500 for the admin).
 */
class ChangeLunchMenuTest extends TestCase
{
    use RefreshDatabase;

    private function menu(Caterer $caterer, string $name, array $options): CatererMeal
    {
        $meal = CatererMeal::create(['caterer_id' => $caterer->id, 'name' => $name, 'price' => 10]);
        foreach ($options as $option) {
            CatererMealOption::create(['caterer_meal_id' => $meal->id, 'meal_option_id' => $option->id]);
        }

        return $meal;
    }

    public function test_saving_again_after_changing_the_menu_keeps_the_new_menus_options(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        $caterer = Caterer::factory()->create();
        [$chicken, $beef, $vegan] = [MealOption::factory()->create(['name' => 'Chicken']), MealOption::factory()->create(['name' => 'Beef']), MealOption::factory()->create(['name' => 'Vegan'])];
        $nasiLemak = $this->menu($caterer, 'Nasi Lemak', [$chicken, $beef]);
        $spaghetti = $this->menu($caterer, 'Spaghetti', [$vegan]);

        $lunch = Lunch::factory()->create(['caterer_id' => $caterer->id, 'caterer_meal_id' => $nasiLemak->id, 'date' => '2026-10-20', 'deadline' => '2026-10-19 10:00']);
        app(LunchPlanningService::class)->syncOptionsFromMenu($lunch);

        $page = Livewire::test(EditLunch::class, ['record' => $lunch->getKey()])
            ->set('data.caterer_meal_id', $spaghetti->id)
            ->call('save')
            ->assertHasNoFormErrors();

        // The admin stays on the page and saves again, e.g. after tweaking a count.
        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame([$vegan->id], $lunch->fresh()->options()->pluck('meal_option_id')->all());
    }
}
