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
