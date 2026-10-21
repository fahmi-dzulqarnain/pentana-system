<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\Pages\EditLunch;
use App\Livewire\Admin\LunchVotes;
use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use App\Notifications\LunchVoteNudge;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class LunchVotesComponentTest extends TestCase
{
    use RefreshDatabase;

    private MealOption $chicken;

    private MealOption $fish;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        Notification::fake();

        $this->chicken = MealOption::factory()->create(['name' => 'Chicken']);
        $this->fish = MealOption::factory()->create(['name' => 'Fish']);
        $this->admin = User::factory()->admin()->create(['name' => 'Zainab Admin']);
    }

    private function lunch(string $status = 'published', ?string $deadline = '+1 day'): Lunch
    {
        $meal = CatererMeal::factory()
            ->for(Caterer::factory()->create(['name' => 'Kak Ros']))
            ->create(['name' => 'Nasi Lemak']);

        return Lunch::factory()
            ->withMenu($meal, [$this->chicken, $this->fish])
            ->create(['status' => $status, 'deadline' => now()->modify($deadline)]);
    }

    private function option(Lunch $lunch, MealOption $mealOption): LunchOption
    {
        return LunchOption::where('lunch_id', $lunch->id)->where('meal_option_id', $mealOption->id)->firstOrFail();
    }

    private function vote(Lunch $lunch, User $user, ?MealOption $mealOption): void
    {
        LunchResponse::create([
            'lunch_id' => $lunch->id,
            'user_id' => $user->id,
            'meal_option_id' => $mealOption?->id,
            'lunch_option_id' => $mealOption ? $this->option($lunch, $mealOption)->id : null,
        ]);
    }

    private function votes(Lunch $lunch)
    {
        return Livewire::actingAs($this->admin)->test(LunchVotes::class, ['lunchId' => $lunch->id]);
    }

    public function test_renders_names_grouped(): void
    {
        $lunch = $this->lunch();
        $this->vote($lunch, User::factory()->create(['name' => 'Ali']), $this->chicken);
        $this->vote($lunch, User::factory()->create(['name' => 'Hafiz']), $this->fish);
        $this->vote($lunch, User::factory()->create(['name' => 'Ahmad']), null);
        User::factory()->create(['name' => 'Ben']);

        $this->votes($lunch)
            ->assertSuccessful()
            ->assertSee('Current orders')
            ->assertSee('Ordering 2 · Not attending 1 · Not responded 2')
            ->assertSeeInOrder(['Nasi Lemak', 'Chicken', 'Ali', 'Fish', 'Hafiz', 'Not attending', 'Ahmad', 'Not responded', 'Ben']);
    }

    public function test_shows_counts_per_menu_and_nobody_for_empty_options(): void
    {
        $lunch = $this->lunch();
        $this->vote($lunch, User::factory()->create(['name' => 'Ali']), $this->chicken);
        $this->vote($lunch, User::factory()->create(['name' => 'Farah']), $this->chicken);

        $this->votes($lunch)
            ->assertSeeText('Nasi Lemak · Kak Ros')
            ->assertSee('Chicken (2)')
            ->assertSee('Fish (0)')
            ->assertSee('Nobody')
            ->assertSee('Not attending (0)');
    }

    public function test_escapes_names(): void
    {
        $lunch = $this->lunch();
        $this->vote($lunch, User::factory()->create(['name' => '<b>x</b>']), $this->chicken);

        $html = $this->votes($lunch)->html();

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_disabled_member_is_tagged(): void
    {
        $lunch = $this->lunch();
        $gone = User::factory()->create(['name' => 'Gone']);
        $this->vote($lunch, $gone, $this->chicken);
        $gone->forceFill(['disabled_at' => now()])->save();

        $this->votes($lunch)->assertSeeInOrder(['Chicken (1)', 'Gone', 'disabled']);
    }

    public function test_admin_sets_pick_by_name(): void
    {
        $lunch = $this->lunch();
        $ben = User::factory()->create(['name' => 'Ben']);
        $chicken = $this->option($lunch, $this->chicken);

        $this->votes($lunch)
            ->call('setPick', $ben->id, $chicken->id)
            ->assertNotified(__('Pick saved.'))
            ->assertSeeInOrder(['Chicken (1)', 'Ben', 'Fish (0)']);

        $this->assertDatabaseHas('lunch_responses', ['lunch_id' => $lunch->id, 'user_id' => $ben->id, 'lunch_option_id' => $chicken->id]);
    }

    public function test_menu_lists_only_enabled_options(): void
    {
        $lunch = $this->lunch();
        $fish = $this->option($lunch, $this->fish);
        $this->vote($lunch, User::factory()->create(['name' => 'Hafiz']), $this->fish);
        $fish->update(['enabled' => false]);
        $chicken = $this->option($lunch, $this->chicken);

        $this->votes($lunch)
            ->assertSee('Fish (1)')
            ->assertSeeHtml('wire:click="setPick('.$this->admin->id.', '.$chicken->id.')"')
            ->assertSeeHtml('wire:click="setPick('.$this->admin->id.', null)"')
            ->assertDontSeeHtml(', '.$fish->id.')"');
    }

    public function test_tampered_option_from_another_lunch_is_rejected(): void
    {
        $lunch = $this->lunch();
        $other = $this->lunch();
        $ben = User::factory()->create(['name' => 'Ben']);

        $this->votes($lunch)
            ->call('setPick', $ben->id, $this->option($other, $this->chicken)->id)
            ->assertNotified(FilamentNotification::make()->title(__('That option is not available for this lunch.'))->danger());

        $this->assertDatabaseMissing('lunch_responses', ['user_id' => $ben->id]);
    }

    public function test_tampered_lunch_id_is_rejected(): void
    {
        $lunch = $this->lunch();
        $other = $this->lunch();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->votes($lunch)->set('lunchId', $other->id);
    }

    public function test_non_admin_cannot_set_or_remind(): void
    {
        $lunch = $this->lunch();
        $ben = User::factory()->create(['name' => 'Ben']);
        // Mounted by an admin, then the session changes hands: each action re-checks who is asking.
        $setPick = $this->votes($lunch);
        $remind = $this->votes($lunch);

        $this->actingAs($ben);

        $setPick->call('setPick', $ben->id, $this->option($lunch, $this->chicken)->id)->assertForbidden();
        $remind->call('remind')->assertForbidden();

        $this->assertDatabaseMissing('lunch_responses', ['user_id' => $ben->id]);
        $this->assertNull($lunch->fresh()->manual_reminder_sent_at);
        Notification::assertNothingSent();
    }

    public function test_non_admin_cannot_mount(): void
    {
        $lunch = $this->lunch();

        Livewire::actingAs(User::factory()->create())
            ->test(LunchVotes::class, ['lunchId' => $lunch->id])
            ->assertForbidden();
    }

    public function test_names_not_clickable_when_closed(): void
    {
        $lunch = $this->lunch('closed', '-1 hour');
        $ali = User::factory()->create(['name' => 'Ali']);
        $this->vote($lunch, $ali, $this->chicken);

        $this->votes($lunch)
            ->assertSee('Ali')
            ->assertDontSeeHtml('wire:click="setPick');
    }

    public function test_setting_a_pick_on_a_closed_lunch_is_rejected(): void
    {
        $lunch = $this->lunch('closed', '-1 hour');
        $ben = User::factory()->create(['name' => 'Ben']);

        $this->votes($lunch)
            ->call('setPick', $ben->id, $this->option($lunch, $this->chicken)->id)
            ->assertNotified(FilamentNotification::make()->title(__('This lunch is not published.'))->danger());

        $this->assertDatabaseMissing('lunch_responses', ['user_id' => $ben->id]);
    }

    public function test_names_clickable_after_deadline_while_published(): void
    {
        $lunch = $this->lunch('published', '-1 hour');

        $this->votes($lunch)->assertSeeHtml('wire:click="setPick');
    }

    public function test_remind_button_hidden_after_deadline(): void
    {
        $lunch = $this->lunch('published', '-1 hour');
        User::factory()->create();

        $this->votes($lunch)->assertDontSee('non-voters');
    }

    public function test_remind_button_hidden_when_everyone_responded(): void
    {
        $lunch = $this->lunch();
        $this->vote($lunch, $this->admin, null);

        $this->votes($lunch)->assertDontSee('non-voters');
    }

    public function test_remind_sends_and_shows_last_sent(): void
    {
        $lunch = $this->lunch();
        $ben = User::factory()->create(['name' => 'Ben']);

        $this->votes($lunch)
            ->assertSee('Remind 2 non-voters')
            ->assertSeeHtml('wire:confirm="Send the last-call reminder to 2 members who haven&#039;t responded?"')
            ->assertDontSee('Last reminder sent')
            ->call('remind')
            ->assertNotified(__('Reminder sent to :count members.', ['count' => 2]))
            ->assertSee('Last reminder sent')
            ->assertSee('by Zainab Admin');

        Notification::assertSentTo($ben, LunchVoteNudge::class);
        $this->assertNotNull($lunch->fresh()->manual_reminder_sent_at);
    }

    public function test_remind_after_deadline_is_rejected(): void
    {
        $lunch = $this->lunch('published', '-1 hour');
        User::factory()->create();

        $this->votes($lunch)
            ->call('remind')
            ->assertNotified(FilamentNotification::make()->title(__('This lunch is not open for voting.'))->danger());

        Notification::assertNothingSent();
    }

    public function test_print_link_opens_in_new_tab(): void
    {
        $lunch = $this->lunch();

        $this->votes($lunch)->assertSeeHtml('href="'.route('lunches.print', $lunch).'"')->assertSeeHtml('target="_blank"');
    }

    public function test_edit_page_embeds_component(): void
    {
        $lunch = $this->lunch();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Current orders')
            ->assertSeeLivewire(LunchVotes::class)
            // Filament also passes `record`; it must not be echoed into the page as an HTML attribute.
            ->assertDontSeeHtml('&quot;attributes&quot;:{&quot;record&quot;');
    }

    public function test_breakdown_remounts_after_publish(): void
    {
        $lunch = $this->lunch('draft');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])
            ->assertDontSeeHtml('wire:click="setPick')
            ->callAction('publish')
            ->assertSeeHtml('wire:click="setPick');
    }
}
