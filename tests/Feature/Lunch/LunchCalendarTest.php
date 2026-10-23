<?php

namespace Tests\Feature\Lunch;

use App\Filament\Pages\LunchCalendar;
use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\CatererMealOption;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
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
        $lunch = Lunch::factory()->withMenu($meal)->create([
            'date' => $lunchDate->toDateString(),
            'deadline' => $lunchDate->copy()->subDay()->setTime(12, 0)->toDateTimeString(),
            'status' => 'draft',
        ]);

        // Sync options and set a default_count so cost > 0
        app(LunchPlanningService::class)->syncOptionsFromMenu($lunch->menus()->first());
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

    public function test_calendar_cell_lists_every_menu_of_the_day(): void
    {
        $regular = MealOption::factory()->create(['name' => 'Regular']);
        $mealA = CatererMeal::factory()->create(['name' => 'Nasi Lemak Royale']);
        $mealB = CatererMeal::factory()->create(['name' => 'Mee Goreng Mamak']);
        $date = now()->startOfMonth()->addDays(2);

        $lunch = Lunch::factory()->withMenu($mealA, [$regular])->withMenu($mealB, [$regular])->create([
            'date' => $date->toDateString(), 'status' => 'published', 'deadline' => now()->addDay(),
        ]);
        $option = LunchOption::where('lunch_id', $lunch->id)->first();
        LunchResponse::create([
            'lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id,
            'lunch_option_id' => $option->id, 'meal_option_id' => $regular->id,
        ]);

        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        $html = Livewire::test(LunchCalendar::class)->assertSuccessful()->html();

        // Rendered in both the desktop grid and the phone agenda.
        $this->assertSame(2, substr_count($html, 'Nasi Lemak Royale'));
        $this->assertSame(2, substr_count($html, 'Mee Goreng Mamak'));
        // The day's count appears once per layout, not once per menu.
        $this->assertSame(2, substr_count($html, '1 ordered'));
    }
}
