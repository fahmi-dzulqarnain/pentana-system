<?php

namespace Tests\Feature\Api;

use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchMenu;
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
            ->assertJsonPath('data.0.options.0.name', 'Beef')
            ->assertJsonPath('data.0.menus.0.options.0.meal_option_id', $option->id)
            ->assertJsonPath('data.0.menus.0.options.0.name', 'Beef')
            ->assertJsonPath('data.0.my_lunch_option_id', null);

        $this->assertNotNull($this->getJson('/api/v1/lunches')->json('data.0.menus.0.options.0.lunch_option_id'));
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

    /** @return array{0: Lunch, 1: LunchOption, 2: LunchOption, 3: MealOption} two menus, both offering the same "Rice" meal option, plus a "Noodle" only on the second. */
    private function twoMenuLunch(): array
    {
        $lunch = Lunch::factory()->create([
            'date' => today()->addDay()->toDateString(),
            'status' => 'published',
            'deadline' => now()->addDay(),
        ]);
        $rice = MealOption::factory()->create(['name' => 'Rice']);
        $noodle = MealOption::factory()->create(['name' => 'Noodle']);
        $mealA = CatererMeal::factory()->create();
        $mealB = CatererMeal::factory()->create();
        $menuA = LunchMenu::create(['lunch_id' => $lunch->id, 'caterer_id' => $mealA->caterer_id, 'caterer_meal_id' => $mealA->id, 'sort' => 0]);
        $menuB = LunchMenu::create(['lunch_id' => $lunch->id, 'caterer_id' => $mealB->caterer_id, 'caterer_meal_id' => $mealB->id, 'sort' => 1]);
        $a = LunchOption::factory()->create(['lunch_id' => $lunch->id, 'lunch_menu_id' => $menuA->id, 'meal_option_id' => $rice->id]);
        $b = LunchOption::factory()->create(['lunch_id' => $lunch->id, 'lunch_menu_id' => $menuB->id, 'meal_option_id' => $rice->id]);
        LunchOption::factory()->create(['lunch_id' => $lunch->id, 'lunch_menu_id' => $menuB->id, 'meal_option_id' => $noodle->id]);

        return [$lunch, $a, $b, $noodle];
    }

    public function test_several_menu_day_hides_legacy_options_and_shows_update_message(): void
    {
        [$lunch, $a, $b] = $this->twoMenuLunch();
        $lunch->load('menus.caterer');
        [$menuA, $menuB] = [$lunch->menus[0], $lunch->menus[1]];
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/lunches')
            ->assertOk()
            ->assertJsonPath('data.0.options', [])
            ->assertJsonPath('data.0.menu', 'Several menus today — update the app to order')
            ->assertJsonPath('data.0.caterer', $menuA->caterer->name.' · '.$menuB->caterer->name)
            ->assertJsonCount(2, 'data.0.menus')
            ->assertJsonCount(1, 'data.0.menus.0.options')
            ->assertJsonCount(2, 'data.0.menus.1.options')
            ->assertJsonPath('data.0.menus.0.id', $menuA->id)
            ->assertJsonPath('data.0.menus.0.caterer', $menuA->caterer->name)
            ->assertJsonPath('data.0.menus.0.options.0.lunch_option_id', $a->id)
            ->assertJsonPath('data.0.menus.1.options.0.lunch_option_id', $b->id);
    }

    public function test_several_menu_update_message_is_translated(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $this->twoMenuLunch();
        Sanctum::actingAs(User::factory()->create(['locale' => 'ms']));

        $this->getJson('/api/v1/lunches')
            ->assertOk()
            ->assertJsonPath('data.0.menu', __('Several menus today — update the app to order', [], 'ms'));
        $this->assertNotSame('Several menus today — update the app to order', __('Several menus today — update the app to order', [], 'ms'));
    }

    public function test_caterer_names_are_distinct_on_a_several_menu_day(): void
    {
        [$lunch] = $this->twoMenuLunch();
        $lunch->load('menus');
        $lunch->menus[1]->update(['caterer_id' => $lunch->menus[0]->caterer_id]);
        Sanctum::actingAs(User::factory()->create());

        $name = $lunch->menus[0]->caterer->name;
        $this->getJson('/api/v1/lunches')->assertJsonPath('data.0.caterer', $name);
    }

    public function test_respond_with_lunch_option_id(): void
    {
        [$lunch, $a, $b] = $this->twoMenuLunch();
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/lunches/{$lunch->id}/respond", ['lunch_option_id' => $b->id])
            ->assertOk()
            ->assertJsonPath('data.responded', true)
            ->assertJsonPath('data.my_lunch_option_id', $b->id)
            ->assertJsonPath('data.my_meal_option_id', $b->meal_option_id);

        $this->assertDatabaseHas('lunch_responses', ['lunch_id' => $lunch->id, 'user_id' => $member->id, 'lunch_option_id' => $b->id]);
    }

    public function test_lunch_option_id_wins_over_legacy_meal_option_id(): void
    {
        [$lunch, $a, $b, $noodle] = $this->twoMenuLunch();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/lunches/{$lunch->id}/respond", ['lunch_option_id' => $a->id, 'meal_option_id' => $noodle->id])
            ->assertOk()
            ->assertJsonPath('data.my_lunch_option_id', $a->id);
    }

    public function test_legacy_respond_works_on_one_menu_day(): void
    {
        [$lunch, $option] = $this->openLunchWithOption();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/lunches/{$lunch->id}/respond", ['meal_option_id' => $option->id])
            ->assertOk()
            ->assertJsonPath('data.my_meal_option_id', $option->id)
            ->assertJsonPath('data.my_lunch_option_id', $lunch->options->first()->id);
    }

    public function test_legacy_respond_is_422_on_ambiguous_several_menu_day(): void
    {
        [$lunch, $a] = $this->twoMenuLunch(); // "Rice" is on both menus
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/lunches/{$lunch->id}/respond", ['meal_option_id' => $a->meal_option_id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please update the app to order for this day.');
        $this->assertSame(0, LunchResponse::count());
    }

    public function test_legacy_not_attending_works_on_several_menu_day(): void
    {
        [$lunch] = $this->twoMenuLunch();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/lunches/{$lunch->id}/respond", ['meal_option_id' => null])
            ->assertOk()
            ->assertJsonPath('data.responded', true)
            ->assertJsonPath('data.my_meal_option_id', null)
            ->assertJsonPath('data.my_lunch_option_id', null);

        $this->postJson("/api/v1/lunches/{$lunch->id}/respond", [])
            ->assertOk()
            ->assertJsonPath('data.responded', true);
    }
}
