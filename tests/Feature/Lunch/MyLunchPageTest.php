<?php

namespace Tests\Feature\Lunch;

use App\Filament\Pages\MyLunch;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyLunchPageTest extends TestCase
{
    use RefreshDatabase;

    private function publishedLunch(array $overrides = []): array
    {
        $lunch = Lunch::factory()->create(array_merge([
            'date' => today()->addDay()->toDateString(),
            'status' => 'published',
            'deadline' => now()->addDay(),
        ], $overrides));
        $option = MealOption::factory()->create(['name' => 'Beef']);
        $lunchOption = LunchOption::factory()->create(['lunch_id' => $lunch->id, 'meal_option_id' => $option->id, 'enabled' => true]);

        return [$lunch, $lunchOption];
    }

    private function actAsPanelUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        return $user;
    }

    public function test_any_logged_in_user_can_access(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertTrue(MyLunch::canAccess());
    }

    public function test_it_lists_upcoming_published_lunches_and_records_a_choice(): void
    {
        [$lunch, $option] = $this->publishedLunch();
        $user = $this->actAsPanelUser();

        Livewire::test(MyLunch::class)
            ->assertSuccessful()
            ->assertSee('Not voted yet')
            ->assertSee('1 lunch needs your vote')
            ->call('openLunch', $lunch->id)
            ->assertSee('Beef')
            ->call('choose', $lunch->id, $option->id)
            ->assertDispatched('lunch-saved')
            ->assertDontSee('1 lunch needs your vote');

        $this->assertDatabaseHas('lunch_responses', [
            'lunch_id' => $lunch->id, 'user_id' => $user->id,
            'lunch_option_id' => $option->id, 'meal_option_id' => $option->meal_option_id,
        ]);
    }

    public function test_not_attending_records_a_null_option(): void
    {
        [$lunch] = $this->publishedLunch();
        $user = $this->actAsPanelUser();

        Livewire::test(MyLunch::class)->call('notAttending', $lunch->id);

        $row = LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->first();
        $this->assertNotNull($row);
        $this->assertNull($row->meal_option_id);
    }

    public function test_voting_does_not_touch_another_users_response(): void
    {
        [$lunch, $option] = $this->publishedLunch();
        $other = User::factory()->create();
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $other->id, 'meal_option_id' => null]);

        $this->actAsPanelUser();
        Livewire::test(MyLunch::class)->call('choose', $lunch->id, $option->id);

        $this->assertNull(LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $other->id])->first()->meal_option_id);
    }

    public function test_a_past_deadline_lunch_cannot_be_voted(): void
    {
        // Date today (so it still lists) but the deadline has already passed.
        [$lunch, $option] = $this->publishedLunch([
            'date' => today()->toDateString(),
            'deadline' => now()->subHour(),
        ]);
        $user = $this->actAsPanelUser();

        Livewire::test(MyLunch::class)->call('choose', $lunch->id, $option->id);

        $this->assertDatabaseMissing('lunch_responses', ['lunch_id' => $lunch->id, 'user_id' => $user->id]);
    }

    public function test_it_shows_an_empty_state_when_there_are_no_lunches(): void
    {
        $this->actAsPanelUser();

        Livewire::test(MyLunch::class)
            ->assertSuccessful()
            ->assertSee('No lunch to order right now');
    }

    /** @return array{0: Lunch, 1: LunchOption, 2: LunchOption} one "Regular" on each of two menus */
    private function twoMenuLunch(): array
    {
        $regular = MealOption::factory()->create(['name' => 'Regular']);
        $lunch = Lunch::factory()
            ->withMenu(CatererMeal::factory()->create(['name' => 'Nasi Lemak Royale']), [$regular])
            ->withMenu(CatererMeal::factory()->create(['name' => 'Mee Goreng Mamak']), [$regular])
            ->create(['date' => today()->addDay()->toDateString(), 'status' => 'published', 'deadline' => now()->addDay()]);
        [$menuA, $menuB] = $lunch->menus()->get()->all();

        return [
            $lunch,
            LunchOption::where('lunch_menu_id', $menuA->id)->first(),
            LunchOption::where('lunch_menu_id', $menuB->id)->first(),
        ];
    }

    public function test_my_lunch_shows_each_menu_and_highlights_the_pick_on_the_right_menu(): void
    {
        [$lunch, $optA, $optB] = $this->twoMenuLunch();
        $user = $this->actAsPanelUser();
        LunchResponse::create([
            'lunch_id' => $lunch->id, 'user_id' => $user->id,
            'lunch_option_id' => $optB->id, 'meal_option_id' => $optB->meal_option_id,
        ]);

        // The agenda row names the pick and its dish; the panel lists both menus with B's option chosen.
        $html = Livewire::test(MyLunch::class)
            ->assertSee('Regular')
            ->assertSee('Mee Goreng Mamak')
            ->call('openLunch', $lunch->id)
            ->assertSee('Nasi Lemak Royale')
            ->html();

        $this->assertSame(1, substr_count($html, 'ml-opt ml-opt--on"'));
        $this->assertMatchesRegularExpression('/class="ml-opt ml-opt--on"[^>]*choose\('.$lunch->id.', '.$optB->id.'\)/s', $html);
        $this->assertDoesNotMatchRegularExpression('/class="ml-opt ml-opt--on"[^>]*choose\('.$lunch->id.', '.$optA->id.'\)/s', $html);
    }

    public function test_my_lunch_closed_state_names_the_option_and_its_menu(): void
    {
        [$lunch, , $optB] = $this->twoMenuLunch();
        $user = $this->actAsPanelUser();
        LunchResponse::create([
            'lunch_id' => $lunch->id, 'user_id' => $user->id,
            'lunch_option_id' => $optB->id, 'meal_option_id' => $optB->meal_option_id,
        ]);
        $lunch->update(['deadline' => now()->subHour(), 'date' => today()->toDateString()]);

        [$menuA, $menuB] = $lunch->menus()->with(['caterer', 'catererMeal'])->get()->all();

        $html = Livewire::test(MyLunch::class)
            ->assertSee("Today · You're having")
            ->call('openLunch', $lunch->id)
            ->assertSee('Ordering closed')
            ->html();

        $this->assertSame(1, preg_match('/<div class="ml-summary">(.*?)<\/div>/s', $html, $m));
        $summary = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($m[1])));
        $this->assertStringContainsString('You ordered: Regular ('.$menuB->label().')', $summary);
        $this->assertStringNotContainsString($menuA->label(), $summary);
    }

    public function test_earlier_days_this_week_stay_visible_but_last_week_does_not(): void
    {
        $this->travelTo(now()->next('Wednesday')->setTime(9, 0));
        [$lunch, , $optB] = $this->twoMenuLunch();
        $user = $this->actAsPanelUser();
        LunchResponse::create([
            'lunch_id' => $lunch->id, 'user_id' => $user->id,
            'lunch_option_id' => $optB->id, 'meal_option_id' => $optB->meal_option_id,
        ]);
        $monday = today()->startOfWeek();
        $lunch->update(['date' => $monday->toDateString(), 'deadline' => $monday->copy()->subDay()->setTime(18, 0)]);
        Lunch::factory()->create(['date' => $monday->copy()->subDays(3)->toDateString(), 'status' => 'published', 'deadline' => now()->subWeek()]);

        // Monday is past: one dimmed line with the pick and its dish.
        $page = Livewire::test(MyLunch::class)->assertSee('Regular')->assertSee('Mee Goreng Mamak')->assertDontSee('Not voted yet');

        $this->assertSame([$lunch->id], $page->instance()->upcomingLunches()->pluck('id')->all());
    }

    public function test_my_lunch_choosing_on_menu_b_after_a_keeps_one_response(): void
    {
        [$lunch, $optA, $optB] = $this->twoMenuLunch();
        $user = $this->actAsPanelUser();

        Livewire::test(MyLunch::class)
            ->call('choose', $lunch->id, $optA->id)
            ->call('choose', $lunch->id, $optB->id);

        $rows = LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->get();
        $this->assertCount(1, $rows);
        $this->assertSame($optB->id, $rows->first()->lunch_option_id);
    }

    public function test_changing_a_pick_offers_undo_and_undo_puts_it_back(): void
    {
        [$lunch, $optA, $optB] = $this->twoMenuLunch();
        $user = $this->actAsPanelUser();

        Livewire::test(MyLunch::class)
            ->call('choose', $lunch->id, $optA->id)
            ->assertNotified('Saved · '.$lunch->date->translatedFormat('D j M').': Regular')
            ->call('choose', $lunch->id, $optB->id)
            ->assertNotified('Changed Regular → Regular')
            ->dispatch('lunch-undo', lunchId: $lunch->id, lunchOptionId: $optA->id)
            ->assertNotified('Put back Regular');

        $this->assertSame($optA->id, LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->value('lunch_option_id'));
    }

    public function test_undo_can_restore_not_attending(): void
    {
        [$lunch, $option] = $this->publishedLunch();
        $user = $this->actAsPanelUser();
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $user->id, 'lunch_option_id' => null, 'meal_option_id' => null]);

        Livewire::test(MyLunch::class)
            ->call('choose', $lunch->id, $option->id)
            ->assertNotified('Changed Not attending → Beef')
            ->dispatch('lunch-undo', lunchId: $lunch->id, lunchOptionId: null);

        $row = LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->first();
        $this->assertNull($row->lunch_option_id);
        $this->assertNull($row->meal_option_id);
    }

    public function test_a_tampered_undo_cannot_pick_another_lunchs_option(): void
    {
        [$lunch, $option] = $this->publishedLunch();
        [, $otherOption] = $this->publishedLunch(['date' => today()->addDays(2)->toDateString()]);
        $user = $this->actAsPanelUser();
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $user->id, 'lunch_option_id' => $option->id, 'meal_option_id' => $option->meal_option_id]);

        Livewire::test(MyLunch::class)
            ->dispatch('lunch-undo', lunchId: $lunch->id, lunchOptionId: $otherOption->id)
            ->assertDispatched('lunch-save-failed');

        $this->assertSame($option->id, LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->value('lunch_option_id'));
    }

    public function test_tapping_the_saved_answer_again_changes_nothing(): void
    {
        [$lunch, $option] = $this->publishedLunch();
        $user = $this->actAsPanelUser();
        $row = LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $user->id, 'lunch_option_id' => $option->id, 'meal_option_id' => $option->meal_option_id]);
        $this->travel(5)->minutes();

        Livewire::test(MyLunch::class)
            ->call('choose', $lunch->id, $option->id)
            ->assertNotDispatched('lunch-saved')
            ->assertNotNotified();

        $this->assertTrue($row->updated_at->equalTo($row->fresh()->updated_at));
    }

    public function test_the_panel_offers_the_next_day_that_still_needs_a_vote(): void
    {
        [$friday, $fridayOption] = $this->publishedLunch(['date' => today()->addDays(2)->toDateString(), 'deadline' => now()->addDay()]);
        [$thursday] = $this->publishedLunch(['date' => today()->addDay()->toDateString(), 'deadline' => now()->addHours(6)]);
        $user = $this->actAsPanelUser();
        LunchResponse::create(['lunch_id' => $friday->id, 'user_id' => $user->id, 'lunch_option_id' => $fridayOption->id, 'meal_option_id' => $fridayOption->meal_option_id]);

        Livewire::test(MyLunch::class)
            ->assertSee('Vote for '.$thursday->date->translatedFormat('D'))
            ->call('openLunch', $friday->id)
            ->assertSee('Next: '.$thursday->date->translatedFormat('D j M'));
    }
}
