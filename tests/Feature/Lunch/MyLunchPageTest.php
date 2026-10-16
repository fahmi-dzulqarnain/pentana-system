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
            ->assertSee('Beef')
            ->call('choose', $lunch->id, $option->id);

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

        $html = Livewire::test(MyLunch::class)
            ->assertSee('Nasi Lemak Royale')
            ->assertSee('Mee Goreng Mamak')
            ->html();

        $this->assertSame(1, substr_count($html, 'ml-btn--active"'));
        $this->assertMatchesRegularExpression('/choose\('.$lunch->id.', '.$optB->id.'\)"[^>]*ml-btn ml-btn--active/s', $html);
        $this->assertDoesNotMatchRegularExpression('/choose\('.$lunch->id.', '.$optA->id.'\)"[^>]*ml-btn--active/s', $html);
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

        $html = Livewire::test(MyLunch::class)->assertSee('Ordering closed.')->html();

        $this->assertSame(1, preg_match('/<div class="ml-locked">(.*?)<\/div>/s', $html, $m));
        $locked = preg_replace('/\s+/', ' ', strip_tags($m[1]));
        $this->assertStringContainsString('You ordered: Regular ('.$menuB->label().')', html_entity_decode($locked));
        $this->assertStringNotContainsString($menuA->label(), html_entity_decode($locked));
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
}
