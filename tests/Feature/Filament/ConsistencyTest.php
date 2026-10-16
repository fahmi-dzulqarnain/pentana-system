<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LunchCalendar;
use App\Filament\Resources\MealOptions\Pages\ListMealOptions;
use App\Filament\Resources\MemberCategories\Pages\ListMemberCategories;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Caterer;
use App\Models\MemberCategory;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class ConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /** Page titles and sidebar items keep Filament's Title Case; every other label is sentence case. */
    private const TITLE_CASE_ALLOWED = ['Lunch Planner', 'Meal Options', 'My Activities', 'My Lunch', 'My Payments'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    public function test_labels_are_sentence_case(): void
    {
        $offenders = [];
        foreach ((new Finder)->files()->in([app_path('Filament'), app_path('Support'), resource_path('views/filament')])->name('*.php') as $file) {
            preg_match_all("/__\\('([A-Z][a-z]+(?: [A-Z][A-Za-z]+)+[^']*)'/", $file->getContents(), $m);
            foreach (array_diff($m[1], self::TITLE_CASE_ALLOWED) as $key) {
                $offenders[] = $key.'  ('.$file->getRelativePathname().')';
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_dates_read_day_first_everywhere(): void
    {
        $member = User::factory()->create(['created_at' => '2026-06-25 12:08:36']);

        Livewire::test(ViewUser::class, ['record' => $member->getKey()])
            ->assertSee('25 Jun 2026, 12:08')
            ->assertDontSee('Jun 25, 2026');
    }

    public function test_empty_lists_say_nothing_yet_instead_of_an_error(): void
    {
        User::query()->delete();
        $this->actingAs(User::factory()->admin()->make(['id' => 999]));

        Livewire::test(ListMealOptions::class)
            ->assertSee('No meal options yet')
            ->assertDontSee('No records found');
    }

    public function test_member_category_dues_are_shown_as_money(): void
    {
        MemberCategory::create(['name' => 'Grade 7-11', 'default_fee' => 15]);

        Livewire::test(ListMemberCategories::class)->assertSee("MYR\u{00A0}15.00");
    }

    public function test_meal_options_are_ordered_by_dragging(): void
    {
        $table = Livewire::test(ListMealOptions::class)->instance()->getTable();

        $this->assertTrue($table->isReorderable());
        $this->assertNull($table->getColumn('sort'));
    }

    public function test_malay_create_button_names_what_it_creates(): void
    {
        app()->setLocale('ms');

        Livewire::test(ListUsers::class)->assertSee('Cipta ahli');
    }

    public function test_planner_explains_that_a_caterer_is_needed_first(): void
    {
        Livewire::test(LunchCalendar::class)->assertSee('Add a caterer and their menu before planning lunches.');

        Caterer::factory()->create();

        Livewire::test(LunchCalendar::class)->assertDontSee('Add a caterer and their menu before planning lunches.');
    }
}
