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
        LunchOption::factory()->create(['lunch_id' => $lunch->id, 'meal_option_id' => $option->id, 'enabled' => true]);

        return [$lunch, $option];
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
            'lunch_id' => $lunch->id, 'user_id' => $user->id, 'meal_option_id' => $option->id,
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
}
