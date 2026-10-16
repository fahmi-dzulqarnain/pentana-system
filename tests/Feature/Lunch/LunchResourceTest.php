<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\LunchResource;
use App\Filament\Resources\Lunches\Pages\CreateLunch;
use App\Filament\Resources\Lunches\Pages\EditLunch;
use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\CatererMealOption;
use App\Models\Lunch;
use App\Models\MealOption;
use App\Models\User;
use App\Services\LunchPlanningService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LunchResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a caterer + caterer_meal with the given number of MealOptions attached.
     */
    private function buildCatererWithMenu(int $optionCount = 2): array
    {
        $caterer = Caterer::factory()->create();
        $meal = CatererMeal::create([
            'caterer_id' => $caterer->id,
            'name' => 'Test Menu',
            'price' => 12.00,
        ]);

        for ($i = 0; $i < $optionCount; $i++) {
            $option = MealOption::factory()->create(['name' => "Option $i"]);
            CatererMealOption::create([
                'caterer_meal_id' => $meal->id,
                'meal_option_id' => $option->id,
                'price' => null,
            ]);
        }

        return [$caterer, $meal];
    }

    public function test_lunch_resource_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(LunchResource::canAccess());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(LunchResource::canAccess());
    }

    private function actingAsAdminOnAccountPanel(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    public function test_creating_a_lunch_auto_generates_its_options(): void
    {
        [$caterer, $meal] = $this->buildCatererWithMenu(2);
        $this->actingAsAdminOnAccountPanel();

        Livewire::test(CreateLunch::class)
            ->fillForm([
                'date' => '2025-08-01',
                'menus' => [['caterer_id' => $caterer->id, 'caterer_meal_id' => $meal->id]],
                'deadline' => '2025-07-31 12:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lunch = Lunch::whereDate('date', '2025-08-01')->firstOrFail();
        $this->assertSame(1, $lunch->menus()->count());
        $this->assertSame(2, $lunch->options()->count());
    }

    public function test_admin_can_create_a_day_with_two_caterers(): void
    {
        [$catererA, $mealA] = $this->buildCatererWithMenu(2);
        [$catererB, $mealB] = $this->buildCatererWithMenu(1);
        $this->actingAsAdminOnAccountPanel();

        Livewire::test(CreateLunch::class)
            ->fillForm([
                'date' => '2025-08-02',
                'menus' => [
                    ['caterer_id' => $catererA->id, 'caterer_meal_id' => $mealA->id],
                    ['caterer_id' => $catererB->id, 'caterer_meal_id' => $mealB->id],
                ],
                'deadline' => '2025-08-01 12:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lunch = Lunch::whereDate('date', '2025-08-02')->firstOrFail();
        $this->assertSame(2, $lunch->menus()->count());
        $menus = $lunch->menus;
        $this->assertSame([$mealA->id, $mealB->id], $menus->pluck('caterer_meal_id')->all());
        $this->assertSame([1, 2], $menus->pluck('sort')->all());
        $this->assertSame(2, $menus[0]->options()->count());
        $this->assertSame(1, $menus[1]->options()->count());
        $this->assertSame(3, $lunch->options()->count());
    }

    public function test_admin_can_create_a_day_with_two_menus_from_one_caterer(): void
    {
        [$caterer, $mealA] = $this->buildCatererWithMenu(1);
        $mealB = CatererMeal::create(['caterer_id' => $caterer->id, 'name' => 'Second Menu', 'price' => 9.00]);
        $this->actingAsAdminOnAccountPanel();

        Livewire::test(CreateLunch::class)
            ->fillForm([
                'date' => '2025-08-03',
                'menus' => [
                    ['caterer_id' => $caterer->id, 'caterer_meal_id' => $mealA->id],
                    ['caterer_id' => $caterer->id, 'caterer_meal_id' => $mealB->id],
                ],
                'deadline' => '2025-08-02 12:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lunch = Lunch::whereDate('date', '2025-08-03')->firstOrFail();
        $this->assertSame(2, $lunch->menus()->count());
        $this->assertSame([$caterer->id, $caterer->id], $lunch->menus->pluck('caterer_id')->all());
    }

    public function test_same_menu_twice_is_rejected(): void
    {
        [$caterer, $meal] = $this->buildCatererWithMenu(1);
        $this->actingAsAdminOnAccountPanel();

        Livewire::test(CreateLunch::class)
            ->fillForm([
                'date' => '2025-08-04',
                'menus' => [
                    ['caterer_id' => $caterer->id, 'caterer_meal_id' => $meal->id],
                    ['caterer_id' => $caterer->id, 'caterer_meal_id' => $meal->id],
                ],
                'deadline' => '2025-08-03 12:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['menus.0.caterer_meal_id', 'menus.1.caterer_meal_id']);

        $this->assertSame(0, Lunch::count());
    }

    public function test_a_day_needs_at_least_one_menu(): void
    {
        $this->actingAsAdminOnAccountPanel();

        Livewire::test(CreateLunch::class)
            ->fillForm([
                'date' => '2025-08-05',
                'menus' => [],
                'deadline' => '2025-08-04 12:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['menus']);

        $this->assertSame(0, Lunch::count());
    }

    public function test_saving_an_edit_syncs_options_for_each_menu(): void
    {
        [$caterer, $meal] = $this->buildCatererWithMenu(1);
        $lunch = Lunch::factory()->withMenu($meal)->create([
            'date' => '2025-10-01',
            'deadline' => '2025-09-30 12:00',
            'status' => 'draft',
        ]);
        app(LunchPlanningService::class)->syncOptionsFromMenu($lunch->menus()->first());
        $this->assertSame(1, $lunch->options()->count());

        $extra = MealOption::factory()->create(['name' => 'Extra']);
        CatererMealOption::create(['caterer_meal_id' => $meal->id, 'meal_option_id' => $extra->id, 'price' => null]);

        $this->actingAsAdminOnAccountPanel();

        Livewire::test(EditLunch::class, ['record' => $lunch->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $lunch->options()->count());
        $this->assertSame(1, $lunch->menus()->count());
    }

    public function test_publish_moves_draft_to_published(): void
    {
        [$caterer, $meal] = $this->buildCatererWithMenu(1);

        $lunch = Lunch::factory()->withMenu($meal)->create([
            'date' => '2025-09-01',
            'deadline' => '2025-08-31 12:00',
            'status' => 'draft',
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getKey()])
            ->callAction('publish')
            ->assertHasNoErrors();

        $this->assertSame('published', $lunch->fresh()->status);
    }
}
