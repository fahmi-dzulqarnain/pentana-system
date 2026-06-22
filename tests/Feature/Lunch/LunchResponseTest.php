<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LunchResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_a_vote_with_relationships(): void
    {
        $lunch = Lunch::factory()->create();
        $user = User::factory()->create();
        $option = MealOption::factory()->create();

        $response = LunchResponse::create([
            'lunch_id' => $lunch->id,
            'user_id' => $user->id,
            'meal_option_id' => $option->id,
        ]);

        $this->assertTrue($response->lunch->is($lunch));
        $this->assertTrue($response->user->is($user));
        $this->assertTrue($response->mealOption->is($option));
        $this->assertTrue($lunch->responses->contains($response));
    }

    public function test_meal_option_is_nullable_meaning_not_attending(): void
    {
        $lunch = Lunch::factory()->create();
        $user = User::factory()->create();

        $response = LunchResponse::create([
            'lunch_id' => $lunch->id,
            'user_id' => $user->id,
            'meal_option_id' => null,
        ]);

        $this->assertNull($response->fresh()->meal_option_id);
    }

    public function test_one_response_per_user_per_lunch_is_enforced(): void
    {
        $lunch = Lunch::factory()->create();
        $user = User::factory()->create();
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $user->id, 'meal_option_id' => null]);

        $this->expectException(QueryException::class);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $user->id, 'meal_option_id' => null]);
    }
}
