<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Caterers\CatererResource;
use App\Filament\Resources\Caterers\Pages\EditCaterer;
use App\Filament\Resources\Caterers\RelationManagers\CatererMealsRelationManager;
use App\Filament\Resources\MealOptions\MealOptionResource;
use App\Filament\Resources\Meals\MealResource;
use App\Models\Caterer;
use App\Models\Meal;
use App\Models\MealOption;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_meal_options_are_admin_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(MealOptionResource::canAccess());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(MealOptionResource::canAccess());
    }

    public function test_caterers_are_admin_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(CatererResource::canAccess());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(CatererResource::canAccess());
    }

    public function test_meals_are_admin_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(MealResource::canAccess());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(MealResource::canAccess());
    }

    public function test_admin_can_render_a_caterer_with_its_menu_manager(): void
    {
        $caterer = Caterer::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditCaterer::class, ['record' => $caterer->getKey()])
            ->assertSuccessful();
    }

    public function test_copy_from_catalog_adds_meals_to_the_caterer(): void
    {
        $caterer = Caterer::factory()->create();
        $option = MealOption::factory()->create(['name' => 'Beef']);
        $meal = Meal::factory()->create(['name' => 'Roti Canai', 'base_price' => 3.50]);
        $meal->options()->attach($option->id);

        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CatererMealsRelationManager::class, [
            'ownerRecord' => $caterer,
            'pageClass' => EditCaterer::class,
        ])
            ->callTableAction('copyFromCatalog', data: ['meals' => [$meal->id]])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $caterer->meals()->count());
        $this->assertDatabaseHas('caterer_meals', ['caterer_id' => $caterer->id, 'name' => 'Roti Canai']);
        $this->assertDatabaseHas('caterer_meal_options', ['meal_option_id' => $option->id]);
    }
}
