<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\Pages\EditLunch;
use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\CatererMealOption;
use App\Models\Lunch;
use App\Models\LunchMenu;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use App\Notifications\LunchPickRemoved;
use App\Services\LunchMenuChanges;
use App\Services\LunchPlanningService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What happens to members' picks when the committee edits a day's menus.
 *
 * Production, 2026-10-16 15:38: saving a lunch again after changing its menu inserted
 * option rows with no meal option (NOT NULL violation, 500 for the admin).
 */
class ChangeLunchMenuTest extends TestCase
{
    use RefreshDatabase;

    private Caterer $caterer;

    private MealOption $chicken;

    private MealOption $beef;

    private MealOption $vegan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $this->withoutDefer(); // MemberNotifier delivers after the response; run it inline here

        $this->caterer = Caterer::factory()->create();
        $this->chicken = MealOption::factory()->create(['name' => 'Chicken']);
        $this->beef = MealOption::factory()->create(['name' => 'Beef']);
        $this->vegan = MealOption::factory()->create(['name' => 'Vegan']);
    }

    private function menu(string $name, array $options, ?Caterer $caterer = null): CatererMeal
    {
        $meal = CatererMeal::create(['caterer_id' => ($caterer ?? $this->caterer)->id, 'name' => $name, 'price' => 10]);
        foreach ($options as $option) {
            CatererMealOption::create(['caterer_meal_id' => $meal->id, 'meal_option_id' => $option->id]);
        }

        return $meal;
    }

    /** A lunch holding the given catalog menus, with their options synced. */
    private function lunch(array $meals, array $attributes = []): Lunch
    {
        $factory = Lunch::factory();
        foreach ($meals as $meal) {
            $factory = $factory->withMenu($meal);
        }

        $lunch = $factory->create($attributes + ['date' => '2026-10-20', 'deadline' => now()->addDay(), 'status' => 'published']);
        foreach ($lunch->menus()->get() as $menu) {
            app(LunchPlanningService::class)->syncOptionsFromMenu($menu);
        }

        return $lunch->fresh();
    }

    private function pick(Lunch $lunch, LunchMenu $menu, MealOption $option, ?User $user = null): LunchResponse
    {
        $lunchOption = $menu->options()->where('meal_option_id', $option->id)->firstOrFail();

        return LunchResponse::create([
            'lunch_id' => $lunch->id,
            'user_id' => ($user ?? User::factory()->create())->id,
            'meal_option_id' => $option->id,
            'lunch_option_id' => $lunchOption->id,
        ]);
    }

    private function edit(Lunch $lunch): Testable
    {
        return Livewire::test(EditLunch::class, ['record' => $lunch->getKey()]);
    }

    /** The page's Save button lives in the form-actions footer, not the header. */
    private function saveAction(): TestAction
    {
        return TestAction::make('save')->schemaComponent('form-actions', schema: 'content');
    }

    private function withoutMenu(Testable $page, LunchMenu $menu): Testable
    {
        $menus = $page->get('data.menus');
        unset($menus["record-{$menu->id}"]);

        return $page->set('data.menus', $menus);
    }

    public function test_saving_again_after_changing_the_menu_keeps_the_new_menus_options(): void
    {
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $lunch = $this->lunch([$nasiLemak], ['status' => 'draft']);
        $menu = $lunch->menus->first();

        $page = $this->edit($lunch)
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $spaghetti->id)
            ->call('save')
            ->assertHasNoFormErrors();

        // The admin stays on the page and saves again, e.g. after tweaking a count.
        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame([$this->vegan->id], $lunch->fresh()->options()->pluck('meal_option_id')->all());
    }

    public function test_swapping_a_menu_carries_picks_with_the_same_meal_option(): void
    {
        Notification::fake();
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $meeGoreng = $this->menu('Mee Goreng', [$this->chicken, $this->vegan]);
        $lunch = $this->lunch([$nasiLemak]);
        $menu = $lunch->menus->first();
        $chickenOption = $menu->options()->where('meal_option_id', $this->chicken->id)->first();
        $response = $this->pick($lunch, $menu, $this->chicken);

        $this->edit($lunch)
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $meeGoreng->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $menu->refresh();
        $this->assertSame($meeGoreng->id, $menu->caterer_meal_id);
        // The menu's options were re-synced to the new catalog menu.
        $this->assertEqualsCanonicalizing([$this->chicken->id, $this->vegan->id], $menu->options()->pluck('meal_option_id')->all());

        $response->refresh();
        $this->assertSame($this->chicken->id, $response->meal_option_id);
        $this->assertSame($menu->options()->where('meal_option_id', $this->chicken->id)->value('id'), $response->lunch_option_id);
        $this->assertSame($chickenOption->id, $response->lunch_option_id);
        Notification::assertNothingSent();
    }

    public function test_picks_that_cannot_carry_over_are_removed_and_members_notified(): void
    {
        Notification::fake();
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $meeGoreng = $this->menu('Mee Goreng', [$this->chicken, $this->vegan]);
        $lunch = $this->lunch([$nasiLemak]);
        $menu = $lunch->menus->first();
        $kept = $this->pick($lunch, $menu, $this->chicken);
        $dropped = $this->pick($lunch, $menu, $this->beef);
        $notAttending = LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => User::factory()->create()->id, 'meal_option_id' => null]);

        $this->edit($lunch)
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $meeGoreng->id)
            ->call('save')
            ->assertActionMounted($this->saveAction()) // dropping picks asks first
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertModelExists($kept);
        $this->assertModelMissing($dropped);
        $this->assertModelExists($notAttending);
        $this->assertSame(0, $lunch->options()->where('meal_option_id', $this->beef->id)->count());

        Notification::assertSentTo($dropped->user, LunchPickRemoved::class);
        Notification::assertNotSentTo($kept->user, LunchPickRemoved::class);
        Notification::assertNotSentTo($notAttending->user, LunchPickRemoved::class);
        Notification::assertSentTimes(LunchPickRemoved::class, 1);
    }

    public function test_the_notice_asks_the_member_to_choose_again(): void
    {
        $nasiLemak = $this->menu('Nasi Lemak', [$this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $lunch = $this->lunch([$nasiLemak], ['date' => '2026-10-20', 'deadline' => '2026-10-19 10:00']);
        $this->travelTo('2026-10-18 09:00');
        $menu = $lunch->menus->first();
        $member = User::factory()->create(['locale' => null]);
        $this->pick($lunch, $menu, $this->beef, $member);

        $this->edit($lunch)
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $spaghetti->id)
            ->call('save')
            ->assertActionMounted($this->saveAction()) // dropping picks asks first
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $data = $member->notifications()->sole()->data;
        $this->assertSame('Your lunch pick for Tue, 20 Oct was removed', $data['title']);
        $this->assertSame('The menu changed. Please choose again by 10:00 AM, Mon 19 Oct.', $data['body']);

        $mail = (new LunchPickRemoved($lunch->fresh()))->toMail($member);
        $this->assertSame('Your lunch pick for Tue, 20 Oct was removed', $mail->subject);
        $this->assertContains('The menu changed. Please choose again by 10:00 AM, Mon 19 Oct.', $mail->introLines);
    }

    public function test_removing_a_menu_removes_its_picks_only(): void
    {
        Notification::fake();
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan, $this->chicken]);
        $lunch = $this->lunch([$nasiLemak, $spaghetti]);
        [$first, $second] = $lunch->menus->all();
        $gone = $this->pick($lunch, $first, $this->chicken);
        $stays = $this->pick($lunch, $second, $this->chicken);

        $this->withoutMenu($this->edit($lunch), $first)
            ->call('save')
            ->assertActionMounted($this->saveAction()) // dropping picks asks first
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame([$second->id], $lunch->menus()->pluck('id')->all());
        $this->assertModelMissing($gone);
        $this->assertModelExists($stays);
        $this->assertSame($second->options()->where('meal_option_id', $this->chicken->id)->value('id'), $stays->fresh()->lunch_option_id);
        Notification::assertSentTo($gone->user, LunchPickRemoved::class);
        Notification::assertSentTimes(LunchPickRemoved::class, 1);
    }

    public function test_adding_a_menu_keeps_existing_picks(): void
    {
        Notification::fake();
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $lunch = $this->lunch([$nasiLemak]);
        $menu = $lunch->menus->first();
        $response = $this->pick($lunch, $menu, $this->beef);

        $this->edit($lunch)
            ->set('data.menus.'.Str::uuid(), ['caterer_id' => $this->caterer->id, 'caterer_meal_id' => $spaghetti->id, 'options' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$nasiLemak->id, $spaghetti->id], $lunch->menus()->pluck('caterer_meal_id')->all());
        $this->assertSame(1, $lunch->menus()->where('caterer_meal_id', $spaghetti->id)->first()->options()->count());
        $this->assertSame($response->lunch_option_id, $response->fresh()->lunch_option_id);
        Notification::assertNothingSent();
    }

    public function test_draft_lunch_changes_send_no_notice(): void
    {
        Notification::fake();
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $lunch = $this->lunch([$nasiLemak], ['status' => 'draft']);
        $menu = $lunch->menus->first();
        $response = $this->pick($lunch, $menu, $this->beef);

        $this->edit($lunch)
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $spaghetti->id)
            ->call('save')
            ->assertActionMounted($this->saveAction()) // dropping picks asks first
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertModelMissing($response);
        Notification::assertNothingSent();
    }

    public function test_menus_are_locked_after_the_deadline_even_before_close_runs(): void
    {
        Notification::fake();
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $other = $this->menu('Other', [$this->beef]);
        $extra = $this->menu('Extra', [$this->chicken]);
        $lunch = $this->lunch([$nasiLemak, $other], ['status' => 'published', 'deadline' => now()->subMinute()]);
        [$first, $second] = $lunch->menus->all();
        $response = $this->pick($lunch, $first, $this->beef);
        $optionIds = $lunch->options()->orderBy('id')->pluck('default_count', 'id')->all();
        $beefOption = $first->options()->where('meal_option_id', $this->beef->id)->first();

        $page = $this->edit($lunch);
        // A tampered request: swap one menu, drop another, add a third and bump a count.
        $page->set("data.menus.record-{$first->id}.caterer_meal_id", $spaghetti->id)
            ->set("data.menus.record-{$first->id}.options.record-{$beefOption->id}.default_count", 9);
        $this->withoutMenu($page, $second)
            ->set('data.menus.'.Str::uuid(), ['caterer_id' => $this->caterer->id, 'caterer_meal_id' => $extra->id, 'options' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$nasiLemak->id, $other->id], $lunch->menus()->pluck('caterer_meal_id')->all());
        $this->assertSame($optionIds, $lunch->options()->orderBy('id')->pluck('default_count', 'id')->all());
        $this->assertModelExists($response);
        Notification::assertNothingSent();
    }

    public function test_menus_are_locked_when_closed(): void
    {
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $lunch = $this->lunch([$nasiLemak], ['status' => 'closed', 'deadline' => now()->addDay()]);
        $menu = $lunch->menus->first();
        $response = $this->pick($lunch, $menu, $this->beef);

        $this->edit($lunch)
            ->assertFormFieldIsDisabled('menus')
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $spaghetti->id)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertActionNotMounted($this->saveAction());

        $this->assertSame($nasiLemak->id, $menu->fresh()->caterer_meal_id);
        $this->assertSame(2, $menu->options()->count());
        $this->assertModelExists($response);
    }

    public function test_a_draft_past_its_deadline_stays_editable(): void
    {
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $lunch = $this->lunch([$nasiLemak], ['status' => 'draft', 'deadline' => now()->subDay()]);
        $menu = $lunch->menus->first();

        $this->edit($lunch)
            ->assertFormFieldIsEnabled('menus')
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $spaghetti->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->vegan->id], $menu->options()->pluck('meal_option_id')->all());
    }

    public function test_moving_the_deadline_into_the_past_in_the_same_save_still_applies_the_menu_change(): void
    {
        Notification::fake();
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $lunch = $this->lunch([$nasiLemak]);
        $menu = $lunch->menus->first();
        $response = $this->pick($lunch, $menu, $this->beef);

        $this->edit($lunch)
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $spaghetti->id)
            ->set('data.deadline', now()->subHour()->format('Y-m-d H:i'))
            ->call('save')
            ->assertActionMounted($this->saveAction()) // dropping picks asks first
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame([$this->vegan->id], $menu->options()->pluck('meal_option_id')->all());
        $this->assertModelMissing($response);
        Notification::assertSentTo($response->user, LunchPickRemoved::class);
    }

    public function test_dropped_responses_match_the_confirmation(): void
    {
        Notification::fake();
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan, $this->chicken]);
        $meeGoreng = $this->menu('Mee Goreng', [$this->chicken]);
        $lunch = $this->lunch([$nasiLemak, $spaghetti]);
        [$first, $second] = $lunch->menus->all();
        $this->pick($lunch, $first, $this->chicken);  // carries to Mee Goreng
        $beef = $this->pick($lunch, $first, $this->beef);    // dropped by the swap
        $vegan = $this->pick($lunch, $second, $this->vegan); // dropped with its menu

        $page = $this->edit($lunch)->set("data.menus.record-{$first->id}.caterer_meal_id", $meeGoreng->id);
        $page = $this->withoutMenu($page, $second);

        $dropped = app(LunchMenuChanges::class)->droppedResponses($lunch->fresh(), $page->get('data.menus'));
        $this->assertEqualsCanonicalizing([$beef->id, $vegan->id], $dropped->modelKeys());

        $page->mountAction($this->saveAction())
            ->assertActionMounted($this->saveAction())
            ->assertMountedActionModalSee("2 members' picks will be removed and they will be asked to choose again.")
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertModelMissing($beef);
        $this->assertModelMissing($vegan);
        $this->assertSame(1, $lunch->responses()->count());
        Notification::assertSentTimes(LunchPickRemoved::class, 2);
    }

    public function test_saving_without_dropped_picks_needs_no_confirmation(): void
    {
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken]);
        $lunch = $this->lunch([$nasiLemak]);
        $menu = $lunch->menus->first();
        $chickenOption = $menu->options()->first();
        $this->pick($lunch, $menu, $this->chicken);

        $this->edit($lunch)
            ->set("data.menus.record-{$menu->id}.options.record-{$chickenOption->id}.default_count", 7)
            ->mountAction($this->saveAction())
            ->assertActionNotMounted($this->saveAction())
            ->assertHasNoFormErrors();

        $this->assertSame(7, $chickenOption->fresh()->default_count);
        $this->assertSame(1, $lunch->responses()->count());
    }

    public function test_a_direct_save_that_would_drop_picks_asks_first_and_drops_nothing(): void
    {
        Notification::fake();
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $lunch = $this->lunch([$nasiLemak]);
        $menu = $lunch->menus->first();
        $response = $this->pick($lunch, $menu, $this->beef);

        // `save` is what the form's wire:submit (Enter) calls; a client can also call it directly.
        $page = $this->edit($lunch)
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $spaghetti->id)
            ->call('save')
            ->assertActionMounted($this->saveAction())
            ->assertMountedActionModalSee("1 members' picks will be removed and they will be asked to choose again.");

        $this->assertSame($nasiLemak->id, $menu->fresh()->caterer_meal_id);
        $this->assertModelExists($response);
        Notification::assertNothingSent();

        // Cancelling and calling save again still doesn't save.
        $page->call('unmountAction')->call('save')->assertActionMounted($this->saveAction());
        $this->assertModelExists($response);
        $this->assertSame($nasiLemak->id, $menu->fresh()->caterer_meal_id);
    }

    public function test_a_tampered_status_is_never_saved(): void
    {
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken, $this->beef]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $lunch = $this->lunch([$nasiLemak], ['status' => 'closed', 'deadline' => now()->addDay()]);
        $menu = $lunch->menus->first();
        $response = $this->pick($lunch, $menu, $this->beef);

        $page = $this->edit($lunch)
            ->set('data.status', 'draft')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('closed', $lunch->fresh()->status);

        // The menus are still locked on the next save.
        $page->set('data.status', 'draft')
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $spaghetti->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('closed', $lunch->fresh()->status);
        $this->assertSame($nasiLemak->id, $menu->fresh()->caterer_meal_id);
        $this->assertModelExists($response);
    }

    public function test_a_menu_from_another_caterer_is_rejected(): void
    {
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken]);
        $foreign = $this->menu('Foreign', [$this->beef], Caterer::factory()->create());
        $lunch = $this->lunch([$nasiLemak]);
        $menu = $lunch->menus->first();

        $this->edit($lunch)
            ->set("data.menus.record-{$menu->id}.caterer_meal_id", $foreign->id)
            ->call('save')
            ->assertHasFormErrors(["menus.record-{$menu->id}.caterer_meal_id"]);

        $this->assertSame($nasiLemak->id, $menu->fresh()->caterer_meal_id);
    }

    public function test_swapping_meals_between_two_menus_gives_a_form_error_not_a_500(): void
    {
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $lunch = $this->lunch([$nasiLemak, $spaghetti]);
        [$first, $second] = $lunch->menus->all();
        $chicken = $this->pick($lunch, $first, $this->chicken);

        $this->edit($lunch)
            ->set("data.menus.record-{$first->id}.caterer_meal_id", $spaghetti->id)
            ->set("data.menus.record-{$second->id}.caterer_meal_id", $nasiLemak->id)
            ->call('save')
            ->assertHasFormErrors(["menus.record-{$first->id}.caterer_meal_id"]);

        $this->assertSame([$nasiLemak->id, $spaghetti->id], $lunch->menus()->pluck('caterer_meal_id')->all());
        $this->assertModelExists($chicken);
    }

    public function test_a_meal_freed_earlier_in_the_same_save_can_be_reused(): void
    {
        // Rows are written in form order: the first row gives Nasi Lemak up before the second takes it.
        $nasiLemak = $this->menu('Nasi Lemak', [$this->chicken]);
        $spaghetti = $this->menu('Spaghetti', [$this->vegan]);
        $meeGoreng = $this->menu('Mee Goreng', [$this->beef]);
        $lunch = $this->lunch([$nasiLemak, $spaghetti]);
        [$first, $second] = $lunch->menus->all();

        $this->edit($lunch)
            ->set("data.menus.record-{$first->id}.caterer_meal_id", $meeGoreng->id)
            ->set("data.menus.record-{$second->id}.caterer_meal_id", $nasiLemak->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$meeGoreng->id, $nasiLemak->id], $lunch->menus()->pluck('caterer_meal_id')->all());
    }
}
