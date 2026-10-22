<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Caterers\Pages\EditCaterer;
use App\Filament\Resources\Caterers\Pages\ListCaterers;
use App\Filament\Resources\Caterers\RelationManagers\CatererMealsRelationManager;
use App\Filament\Resources\MealOptions\Pages\EditMealOption;
use App\Filament\Resources\MealOptions\Pages\ListMealOptions;
use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Caterers, menus and options that lunch days use are refused with a notice, never a 500. */
class LunchDayDeleteGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    /** A meal served on two lunch days. */
    private function mealOnTwoDays(array $mealOptions = []): CatererMeal
    {
        $meal = CatererMeal::factory()->create();
        Lunch::factory()->withMenu($meal, $mealOptions)->create();
        Lunch::factory()->withMenu($meal, $mealOptions)->create();

        return $meal;
    }

    /** The plain-text bodies of the notifications the last call sent. */
    private function notificationBodies(): array
    {
        // Filament 5.10+ delivers notifications through its mailbox rather than the session;
        // read them the way Notification::assertNotified() does.
        app(NotificationMailbox::class)->flush();
        $component = new Notifications;
        $component->mount();
        $component->pullNotificationsFromSession();

        return $component->notifications
            ->map(fn (Notification $notification) => $notification->getBody())
            ->filter()->map(fn (string $body) => trim(strip_tags($body)))->values()->all();
    }

    public function test_a_caterer_used_by_lunch_days_is_not_deleted(): void
    {
        $caterer = $this->mealOnTwoDays()->caterer;

        Livewire::test(EditCaterer::class, ['record' => $caterer->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified("This caterer is used by 2 lunch days, so it can't be deleted.");

        $this->assertModelExists($caterer);
    }

    public function test_an_unused_caterer_is_still_deleted(): void
    {
        $caterer = Caterer::factory()->create();

        Livewire::test(EditCaterer::class, ['record' => $caterer->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($caterer);
    }

    public function test_bulk_caterer_delete_skips_the_used_ones(): void
    {
        $used = $this->mealOnTwoDays()->caterer;
        $unused = Caterer::factory()->create();

        Livewire::test(ListCaterers::class)->callTableBulkAction('delete', [$used, $unused]);

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
        $this->assertContains('Skipped 1 still used by lunch days.', $this->notificationBodies());
    }

    public function test_a_menu_used_by_lunch_days_is_not_deleted(): void
    {
        $meal = $this->mealOnTwoDays();

        Livewire::test(CatererMealsRelationManager::class, ['ownerRecord' => $meal->caterer, 'pageClass' => EditCaterer::class])
            ->callTableAction(DeleteAction::class, $meal)
            ->assertNotified("This menu is used by 2 lunch days, so it can't be deleted.");

        $this->assertModelExists($meal);
    }

    public function test_bulk_menu_delete_skips_the_used_ones(): void
    {
        $used = $this->mealOnTwoDays();
        $unused = CatererMeal::factory()->create(['caterer_id' => $used->caterer_id]);

        Livewire::test(CatererMealsRelationManager::class, ['ownerRecord' => $used->caterer, 'pageClass' => EditCaterer::class])
            ->callTableBulkAction('delete', [$used, $unused]);

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
        $this->assertContains('Skipped 1 still used by lunch days.', $this->notificationBodies());
    }

    public function test_an_option_used_by_lunch_days_is_not_deleted_and_picks_survive(): void
    {
        $option = MealOption::factory()->create();
        $meal = $this->mealOnTwoDays([$option]);
        $lunch = Lunch::whereHas('menus', fn ($m) => $m->where('caterer_meal_id', $meal->id))->first();
        $pick = LunchResponse::create([
            'lunch_id' => $lunch->id,
            'user_id' => User::factory()->create()->id,
            'meal_option_id' => $option->id,
            'lunch_option_id' => $lunch->options()->sole()->id,
        ]);

        Livewire::test(EditMealOption::class, ['record' => $option->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified("This option is used by 2 lunch days, so it can't be deleted.");

        $this->assertModelExists($option);
        $this->assertModelExists($pick);
    }

    public function test_bulk_option_delete_skips_the_used_ones(): void
    {
        $used = MealOption::factory()->create();
        $this->mealOnTwoDays([$used]);
        $unused = MealOption::factory()->create();

        Livewire::test(ListMealOptions::class)->callTableBulkAction('delete', [$used, $unused]);

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
        $this->assertContains('Skipped 1 still used by lunch days.', $this->notificationBodies());
    }
}
