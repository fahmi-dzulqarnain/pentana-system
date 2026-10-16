<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\Pages\EditLunch;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use App\Services\LunchPlanningStats;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LunchOrderStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lunch_count_helpers(): void
    {
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);
        $beef = MealOption::factory()->create();
        $chicken = MealOption::factory()->create();

        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $beef->id]);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $beef->id]);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $chicken->id]);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => null]);

        $lunch->refresh()->load('responses');

        $this->assertSame(3, $lunch->attendingCount());
        $this->assertSame(1, $lunch->notAttendingCount());
        $this->assertSame([$beef->id => 2, $chicken->id => 1], $lunch->optionCounts());
    }

    public function test_avg_orders_per_day_over_published_or_closed_lunches(): void
    {
        $month = now()->startOfMonth();
        $stats = app(LunchPlanningStats::class);

        // Empty month → 0.0
        $this->assertSame(0.0, $stats->avgOrdersPerDay($month));

        // Two opened lunches: 4 attending + 2 attending → avg 3.0
        $a = Lunch::factory()->create(['date' => $month->copy()->addDays(1)->toDateString(), 'status' => 'published', 'deadline' => now()->addDay()]);
        $b = Lunch::factory()->create(['date' => $month->copy()->addDays(2)->toDateString(), 'status' => 'closed', 'deadline' => now()->subDay()]);
        // A draft in the same month must NOT drag the average down.
        Lunch::factory()->create(['date' => $month->copy()->addDays(3)->toDateString(), 'status' => 'draft', 'deadline' => now()->addDay()]);

        $option = MealOption::factory()->create();
        foreach (range(1, 4) as $i) {
            LunchResponse::create(['lunch_id' => $a->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $option->id]);
        }
        foreach (range(1, 2) as $i) {
            LunchResponse::create(['lunch_id' => $b->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $option->id]);
        }

        $this->assertSame(3.0, $stats->avgOrdersPerDay($month));
    }

    public function test_edit_page_shows_the_orders_tally(): void
    {
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => null]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Current orders')
            ->assertSee('Not attending: 1')
            ->assertSee('Total ordering: 0');
    }

    public function test_orders_summary_groups_counts_per_menu(): void
    {
        $regular = MealOption::factory()->create(['name' => 'Regular']);
        $large = MealOption::factory()->create(['name' => 'Large']);
        $mealA = CatererMeal::factory()->create(['name' => 'Nasi Lemak']);
        $mealB = CatererMeal::factory()->create(['name' => 'Mee Goreng']);

        $lunch = Lunch::factory()->withMenu($mealA, [$regular])->withMenu($mealB, [$regular, $large])
            ->create(['status' => 'published', 'deadline' => now()->addDay()]);
        [$menuA, $menuB] = $lunch->menus()->get()->all();
        $optA = LunchOption::where('lunch_menu_id', $menuA->id)->where('meal_option_id', $regular->id)->first();
        $optBRegular = LunchOption::where('lunch_menu_id', $menuB->id)->where('meal_option_id', $regular->id)->first();
        $optBLarge = LunchOption::where('lunch_menu_id', $menuB->id)->where('meal_option_id', $large->id)->first();

        $pick = fn (LunchOption $o) => LunchResponse::create([
            'lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id,
            'lunch_option_id' => $o->id, 'meal_option_id' => $o->meal_option_id,
        ]);
        $pick($optA);
        $pick($optBRegular);
        $pick($optBRegular);
        $pick($optBLarge);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => null]);

        $this->assertSame([$optA->id => 1, $optBRegular->id => 2, $optBLarge->id => 1], $lunch->fresh('responses')->optionCountsByLunchOption());

        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])
            ->assertSee($menuA->fresh(['caterer', 'catererMeal'])->label().': Regular: 1')
            ->assertSee($menuB->fresh(['caterer', 'catererMeal'])->label().': Regular: 2')
            ->assertSee('Large: 1')
            ->assertSee('Not attending: 1')
            ->assertSee('Total ordering: 4');
    }
}
