<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LunchPrintTest extends TestCase
{
    use RefreshDatabase;

    private Lunch $lunch;

    protected function setUp(): void
    {
        parent::setUp();

        $chicken = MealOption::factory()->create(['name' => 'Chicken']);
        $fish = MealOption::factory()->create(['name' => 'Fish']);
        $meal = CatererMeal::factory()
            ->for(Caterer::factory()->create(['name' => 'Kak Ros']))
            ->create(['name' => 'Nasi Lemak']);

        $this->lunch = Lunch::factory()->withMenu($meal, [$chicken, $fish])->create(['status' => 'published']);

        $option = LunchOption::where('lunch_id', $this->lunch->id)->where('meal_option_id', $chicken->id)->firstOrFail();
        foreach (['Ali', 'Bob'] as $name) {
            LunchResponse::create([
                'lunch_id' => $this->lunch->id,
                'user_id' => User::factory()->create(['name' => $name])->id,
                'meal_option_id' => $chicken->id,
                'lunch_option_id' => $option->id,
            ]);
        }
        LunchResponse::create([
            'lunch_id' => $this->lunch->id,
            'user_id' => User::factory()->create(['name' => 'Cara'])->id,
            'meal_option_id' => null,
            'lunch_option_id' => null,
        ]);
        User::factory()->create(['name' => 'Dina']);
    }

    public function test_admin_sees_grouped_print_with_tick_boxes(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'name' => 'Zed Admin']);

        $this->actingAs($admin)
            ->get(route('lunches.print', $this->lunch))
            ->assertOk()
            ->assertSeeInOrder(['Nasi Lemak (Kak Ros)', 'Chicken: 2', '☐', 'Ali', 'Not attending: 1', 'Not responded: 2']);
    }

    public function test_member_gets_403(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('lunches.print', $this->lunch))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_account_login(): void
    {
        $this->get(route('lunches.print', $this->lunch))
            ->assertRedirect(Filament::getPanel('account')->getLoginUrl());
    }

    public function test_works_on_a_closed_lunch(): void
    {
        $this->lunch->update(['status' => 'closed']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('lunches.print', $this->lunch))
            ->assertOk();
    }
}
