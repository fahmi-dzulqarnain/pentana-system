<?php

namespace Tests\Feature\Lunch;

use App\Filament\Pages\LunchCalendar;
use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\CatererMealOption;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\MealOption;
use App\Models\User;
use App\Services\LunchPlanningService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LunchCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(LunchCalendar::canAccess());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(LunchCalendar::canAccess());
    }

    public function test_calendar_renders_and_shows_a_planned_day_and_stats(): void
    {
        // Build fixtures
        $caterer = Caterer::factory()->create();
        $meal = CatererMeal::create([
            'caterer_id' => $caterer->id,
            'name' => 'Nasi Lemak',
            'price' => 12.00,
        ]);
        $mealOption = MealOption::factory()->create(['name' => 'Beef Rendang']);
        CatererMealOption::create([
            'caterer_meal_id' => $meal->id,
            'meal_option_id' => $mealOption->id,
            'price' => 14.00,
        ]);

        // Create a lunch in the current month
        $lunchDate = now()->startOfMonth()->addDays(2);
        $lunch = Lunch::create([
            'date' => $lunchDate->toDateString(),
            'caterer_id' => $caterer->id,
            'caterer_meal_id' => $meal->id,
            'deadline' => $lunchDate->copy()->subDay()->setTime(12, 0)->toDateTimeString(),
            'status' => 'draft',
        ]);

        // Sync options and set a default_count so cost > 0
        app(LunchPlanningService::class)->syncOptionsFromMenu($lunch);
        LunchOption::where('lunch_id', $lunch->id)->update(['default_count' => 3]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        $component = Livewire::test(LunchCalendar::class)
            ->assertSuccessful()
            ->assertSee('Nasi Lemak')
            ->assertSee('Lunch Planner');

        // Assert stats computed correctly
        $this->assertSame(1, $component->instance()->daysPlanned());
        // 3 × 14.00 = 42.00
        $this->assertSame(42.0, $component->instance()->estimatedCost());
    }
}
