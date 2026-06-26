<?php

namespace Tests\Feature\Api;

use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LunchApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Lunch, 1: MealOption} */
    private function openLunchWithOption(): array
    {
        $lunch = Lunch::factory()->create([
            'date' => today()->addDay()->toDateString(),
            'status' => 'published',
            'deadline' => now()->addDay(),
        ]);
        $option = MealOption::factory()->create(['name' => 'Beef']);
        LunchOption::factory()->create(['lunch_id' => $lunch->id, 'meal_option_id' => $option->id, 'enabled' => true]);

        return [$lunch, $option];
    }

    public function test_lunches_require_authentication(): void
    {
        $this->getJson('/api/v1/lunches')->assertStatus(401);
    }

    public function test_it_lists_only_upcoming_published_lunches_with_options(): void
    {
        [$open, $option] = $this->openLunchWithOption();
        Lunch::factory()->create(['date' => today()->subDay()->toDateString(), 'status' => 'published', 'deadline' => now()->subDays(2)]);
        // Different date (lunches.date is unique); excluded by status, not date.
        Lunch::factory()->create(['date' => today()->addDays(2)->toDateString(), 'status' => 'draft', 'deadline' => now()->addDay()]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/lunches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.is_open', true)
            ->assertJsonPath('data.0.responded', false)
            ->assertJsonPath('data.0.options.0.meal_option_id', $option->id)
            ->assertJsonPath('data.0.options.0.name', 'Beef');
    }

    public function test_member_can_vote_for_an_option(): void
    {
        [$lunch, $option] = $this->openLunchWithOption();
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/lunches/{$lunch->id}/respond", ['meal_option_id' => $option->id])
            ->assertOk()
            ->assertJsonPath('data.responded', true)
            ->assertJsonPath('data.my_meal_option_id', $option->id);

        $this->assertDatabaseHas('lunch_responses', [
            'lunch_id' => $lunch->id, 'user_id' => $member->id, 'meal_option_id' => $option->id,
        ]);
    }

    public function test_member_can_mark_not_attending(): void
    {
        [$lunch] = $this->openLunchWithOption();
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/lunches/{$lunch->id}/respond", ['meal_option_id' => null])
            ->assertOk()
            ->assertJsonPath('data.responded', true)
            ->assertJsonPath('data.my_meal_option_id', null);
    }

    public function test_voting_after_the_deadline_is_rejected(): void
    {
        $lunch = Lunch::factory()->create([
            'date' => today()->addDay()->toDateString(),
            'status' => 'published',
            'deadline' => now()->subHour(),
        ]);
        $option = MealOption::factory()->create();
        LunchOption::factory()->create(['lunch_id' => $lunch->id, 'meal_option_id' => $option->id, 'enabled' => true]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/lunches/{$lunch->id}/respond", ['meal_option_id' => $option->id])
            ->assertStatus(422);
        $this->assertSame(0, LunchResponse::count());
    }

    public function test_an_invalid_option_is_rejected(): void
    {
        [$lunch] = $this->openLunchWithOption();
        $otherOption = MealOption::factory()->create(); // not attached to this lunch
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/lunches/{$lunch->id}/respond", ['meal_option_id' => $otherOption->id])
            ->assertStatus(422);
    }

    public function test_my_response_is_scoped_to_the_caller(): void
    {
        [$lunch, $option] = $this->openLunchWithOption();
        LunchResponse::factory()->create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => $option->id]);

        Sanctum::actingAs(User::factory()->create()); // a different member, who hasn't voted

        $this->getJson('/api/v1/lunches')
            ->assertOk()
            ->assertJsonPath('data.0.responded', false)
            ->assertJsonPath('data.0.my_meal_option_id', null);
    }
}
