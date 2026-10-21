<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use App\Notifications\LunchVoteNudge;
use App\Services\LunchVoteBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LunchVoteBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private MealOption $chicken;

    private MealOption $fish;

    private Lunch $lunch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chicken = MealOption::factory()->create(['name' => 'Chicken']);
        $this->fish = MealOption::factory()->create(['name' => 'Fish']);

        $meal = CatererMeal::factory()
            ->for(Caterer::factory()->create(['name' => 'Kak Ros']))
            ->create(['name' => 'Nasi Lemak']);

        $this->lunch = Lunch::factory()
            ->withMenu($meal, [$this->chicken, $this->fish])
            ->create(['status' => 'published']);
    }

    private function option(MealOption $mealOption): LunchOption
    {
        return LunchOption::where('lunch_id', $this->lunch->id)->where('meal_option_id', $mealOption->id)->firstOrFail();
    }

    private function vote(User $user, ?MealOption $mealOption): void
    {
        LunchResponse::create([
            'lunch_id' => $this->lunch->id,
            'user_id' => $user->id,
            'meal_option_id' => $mealOption?->id,
            'lunch_option_id' => $mealOption ? $this->option($mealOption)->id : null,
        ]);
    }

    private function breakdown(): array
    {
        return LunchVoteBreakdown::for($this->lunch->fresh());
    }

    private function names(array $members): array
    {
        return array_column($members, 'name');
    }

    public function test_groups_members_by_menu_and_option_sorted_by_name(): void
    {
        $this->vote(User::factory()->create(['name' => 'Zara']), $this->chicken);
        $this->vote(User::factory()->create(['name' => 'ali']), $this->chicken);
        $this->vote(User::factory()->create(['name' => 'Hafiz']), $this->fish);

        $result = $this->breakdown();

        $this->assertSame('Nasi Lemak', $result['menus'][0]['meal']);
        $this->assertSame('Kak Ros', $result['menus'][0]['caterer']);
        $options = collect($result['menus'][0]['options'])->keyBy('name');
        $this->assertSame(['ali', 'Zara'], $this->names($options['Chicken']['members']));
        $this->assertSame(['Hafiz'], $this->names($options['Fish']['members']));
        $this->assertSame($this->option($this->chicken)->id, $options['Chicken']['lunch_option_id']);
        $this->assertTrue($options['Chicken']['enabled']);
    }

    public function test_enabled_option_with_no_picks_is_listed_empty(): void
    {
        $this->vote(User::factory()->create(), $this->chicken);

        $options = collect($this->breakdown()['menus'][0]['options'])->keyBy('name');

        $this->assertSame([], $options['Fish']['members']);
    }

    public function test_disabled_option_shows_only_when_picked(): void
    {
        $menu = $this->lunch->menus()->first();
        $picked = MealOption::factory()->create(['name' => 'Beef']);
        $unpicked = MealOption::factory()->create(['name' => 'Vegan']);
        foreach ([$picked, $unpicked] as $mealOption) {
            LunchOption::create([
                'lunch_id' => $this->lunch->id,
                'lunch_menu_id' => $menu->id,
                'meal_option_id' => $mealOption->id,
                'enabled' => false,
                'default_count' => 0,
            ]);
        }
        $this->vote(User::factory()->create(), $picked);

        $options = collect($this->breakdown()['menus'][0]['options'])->keyBy('name');

        $this->assertArrayHasKey('Beef', $options->all());
        $this->assertFalse($options['Beef']['enabled']);
        $this->assertArrayNotHasKey('Vegan', $options->all());
    }

    public function test_not_attending_and_not_responded(): void
    {
        $decliner = User::factory()->create(['name' => 'Decliner']);
        $silent = User::factory()->create(['name' => 'Silent']);
        User::factory()->create(['name' => 'Gone', 'disabled_at' => now()]);
        $this->vote($decliner, null);

        $result = $this->breakdown();

        $this->assertSame(['Decliner'], $this->names($result['not_attending']));
        $this->assertSame(['Silent'], $this->names($result['not_responded']));
        $this->assertSame($silent->id, $result['not_responded'][0]['id']);
    }

    public function test_disabled_responder_stays_in_group_with_flag(): void
    {
        $user = User::factory()->create(['name' => 'Leaver']);
        $this->vote($user, $this->chicken);
        $user->forceFill(['disabled_at' => now()])->save();

        $result = $this->breakdown();

        $chicken = collect($result['menus'][0]['options'])->firstWhere('name', 'Chicken');
        $this->assertSame([['id' => $user->id, 'name' => 'Leaver', 'disabled' => true]], $chicken['members']);
        $this->assertSame(1, $result['counts']['ordering']);
        $this->assertSame([], $result['not_responded']);
    }

    public function test_counts(): void
    {
        $this->vote(User::factory()->create(), $this->chicken);
        $this->vote(User::factory()->create(), $this->chicken);
        $this->vote(User::factory()->create(), $this->fish);
        $this->vote(User::factory()->create(), null);
        User::factory()->count(2)->create();

        $this->assertSame(
            ['ordering' => 3, 'not_attending' => 1, 'not_responded' => 2],
            $this->breakdown()['counts'],
        );
    }

    public function test_non_responders_query_matches_the_nudge_command(): void
    {
        Notification::fake();
        $this->lunch->update(['deadline' => now()->addHours(12)]);
        $this->vote(User::factory()->create(), $this->chicken);
        User::factory()->create(['disabled_at' => now()]);
        $silent = User::factory()->count(2)->create();

        $expected = $this->lunch->nonResponders()->pluck('id')->sort()->values()->all();
        $this->assertSame($silent->pluck('id')->sort()->values()->all(), $expected);

        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();

        Notification::assertSentTimes(LunchVoteNudge::class, count($expected));
        foreach ($expected as $id) {
            Notification::assertSentTo(User::find($id), LunchVoteNudge::class);
        }
    }
}
