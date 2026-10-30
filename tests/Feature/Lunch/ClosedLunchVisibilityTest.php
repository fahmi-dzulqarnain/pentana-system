<?php

namespace Tests\Feature\Lunch;

use App\Filament\Pages\MyLunch;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * app:close-lunch-orders flips a lunch to 'closed' 15 minutes or less after its deadline. Members
 * must still see that day (what they're having today, what they ordered earlier this week) on the
 * web, in the app's Lunch tab and on its Home card. Drafts stay hidden everywhere.
 */
class ClosedLunchVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Lunch $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(12, 0)); // a Wednesday noon

        $this->member = User::factory()->create();
        $this->today = Lunch::factory()->create([
            'date' => today()->toDateString(),
            'status' => 'published',
            'deadline' => today()->subDay()->setTime(16, 0),
        ]);
        $option = LunchOption::factory()->create([
            'lunch_id' => $this->today->id,
            'meal_option_id' => MealOption::factory()->create(['name' => 'Salad'])->id,
            'enabled' => true,
        ]);
        LunchResponse::create([
            'lunch_id' => $this->today->id, 'user_id' => $this->member->id,
            'lunch_option_id' => $option->id, 'meal_option_id' => $option->meal_option_id,
        ]);
        Lunch::factory()->create([
            'date' => today()->addDay()->toDateString(),
            'status' => 'draft',
            'deadline' => now()->addHours(4),
        ]);

        $this->artisan('app:close-lunch-orders')->assertSuccessful();
        $this->assertSame('closed', $this->today->fresh()->status);
    }

    public function test_the_app_lunch_tab_still_lists_a_closed_lunch_but_not_drafts(): void
    {
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/lunches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->today->id)
            ->assertJsonPath('data.0.is_open', false)
            ->assertJsonPath('data.0.responded', true);
    }

    public function test_the_app_home_card_still_shows_todays_closed_lunch(): void
    {
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.next_lunch.id', $this->today->id)
            ->assertJsonPath('data.other_lunch', null);
    }

    public function test_the_web_page_still_shows_todays_closed_lunch(): void
    {
        $this->actingAs($this->member);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(MyLunch::class)
            ->assertSee("Today · You're having")
            ->assertSee('Salad')
            ->assertDontSee('No lunch to order right now');
    }

    public function test_a_closed_lunch_still_cannot_be_voted(): void
    {
        Sanctum::actingAs($this->member);
        $other = LunchOption::factory()->create([
            'lunch_id' => $this->today->id,
            'meal_option_id' => MealOption::factory()->create(['name' => 'Wrap'])->id,
            'enabled' => true,
        ]);

        $this->postJson("/api/v1/lunches/{$this->today->id}/respond", ['lunch_option_id' => $other->id])
            ->assertStatus(422);
    }
}
