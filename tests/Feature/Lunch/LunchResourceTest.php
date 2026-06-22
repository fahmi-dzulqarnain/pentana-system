<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\LunchResource;
use App\Filament\Resources\Lunches\Pages\CreateLunch;
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

class LunchResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a caterer + caterer_meal with the given number of MealOptions attached.
     */
    private function buildCatererWithMenu(int $optionCount = 2): array
    {
        $caterer = Caterer::factory()->create();
        $meal = CatererMeal::create([
            'caterer_id' => $caterer->id,
            'name' => 'Test Menu',
            'price' => 12.00,
        ]);

        for ($i = 0; $i < $optionCount; $i++) {
            $option = MealOption::factory()->create(['name' => "Option $i"]);
            CatererMealOption::create([
                'caterer_meal_id' => $meal->id,
                'meal_option_id' => $option->id,
                'price' => null,
            ]);
        }

        return [$caterer, $meal];
    }

    public function test_lunch_resource_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(LunchResource::canAccess());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(LunchResource::canAccess());
    }

    public function test_creating_a_lunch_auto_generates_its_options(): void
    {
        [$caterer, $meal] = $this->buildCatererWithMenu(2);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CreateLunch::class)
            ->fillForm([
                'date' => '2025-08-01',
                'caterer_id' => $caterer->id,
                'caterer_meal_id' => $meal->id,
                'deadline' => '2025-07-31 12:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lunch = Lunch::whereDate('date', '2025-08-01')->firstOrFail();
        $this->assertSame(2, $lunch->options()->count());
    }

    public function test_changing_the_menu_on_edit_resyncs_options(): void
    {
        // Build caterer A with mealA (2 options: Beef, Chicken).
        $caterer = Caterer::factory()->create();

        $mealA = CatererMeal::create([
            'caterer_id' => $caterer->id,
            'name' => 'Menu A',
            'price' => 12.00,
        ]);
        $beef = MealOption::factory()->create(['name' => 'Beef']);
        $chicken = MealOption::factory()->create(['name' => 'Chicken']);
        CatererMealOption::create(['caterer_meal_id' => $mealA->id, 'meal_option_id' => $beef->id, 'price' => null]);
        CatererMealOption::create(['caterer_meal_id' => $mealA->id, 'meal_option_id' => $chicken->id, 'price' => null]);

        // Build mealB (1 option: Vegan) on the same caterer.
        $mealB = CatererMeal::create([
            'caterer_id' => $caterer->id,
            'name' => 'Menu B',
            'price' => 10.00,
        ]);
        $vegan = MealOption::factory()->create(['name' => 'Vegan']);
        CatererMealOption::create(['caterer_meal_id' => $mealB->id, 'meal_option_id' => $vegan->id, 'price' => null]);

        // Create a lunch on mealA and sync its options (simulates the CreateLunch path).
        $lunch = Lunch::create([
            'date' => '2025-10-01',
            'caterer_id' => $caterer->id,
            'caterer_meal_id' => $mealA->id,
            'deadline' => '2025-09-30 12:00',
            'status' => 'draft',
        ]);
        app(LunchPlanningService::class)->syncOptionsFromMenu($lunch);
        $this->assertSame(2, $lunch->options()->count());

        // Act: edit lunch to switch to mealB via the Filament EditLunch page.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getKey()])
            ->fillForm([
                'caterer_meal_id' => $mealB->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // Assert: options reset to exactly the 1 vegan option from mealB.
        $fresh = $lunch->fresh();
        $this->assertSame(1, $fresh->options()->count());
        $this->assertSame($vegan->id, $fresh->options()->first()->meal_option_id);
    }

    public function test_publish_moves_draft_to_published(): void
    {
        [$caterer, $meal] = $this->buildCatererWithMenu(1);

        $lunch = Lunch::create([
            'date' => '2025-09-01',
            'caterer_id' => $caterer->id,
            'caterer_meal_id' => $meal->id,
            'deadline' => '2025-08-31 12:00',
            'status' => 'draft',
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getKey()])
            ->callAction('publish')
            ->assertHasNoErrors();

        $this->assertSame('published', $lunch->fresh()->status);
    }
}
