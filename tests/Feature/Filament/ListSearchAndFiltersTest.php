<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Lunches\Pages\ListLunches;
use App\Filament\Resources\MemberCategories\Pages\ListMemberCategories;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\MemberCategory;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class ListSearchAndFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['activated_at' => now()]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    public function test_every_admin_list_can_be_searched(): void
    {
        $unsearchable = [];
        foreach ((new Finder)->files()->in(app_path('Filament/Resources'))->path('Pages')->name('List*.php') as $file) {
            $class = 'App\\Filament\\Resources\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (is_subclass_of($class, ListRecords::class) && ! Livewire::test($class)->instance()->getTable()->isSearchable()) {
                $unsearchable[] = $class;
            }
        }

        $this->assertSame([], $unsearchable);
    }

    public function test_lunches_can_be_searched_by_menu_and_filtered_by_status(): void
    {
        $caterer = Caterer::factory()->create(['name' => 'Dapur Selera']);
        $nasi = Lunch::factory()->create(['caterer_id' => $caterer->id, 'caterer_meal_id' => CatererMeal::create(['caterer_id' => $caterer->id, 'name' => 'Nasi Lemak', 'price' => 10])->id, 'date' => '2026-10-20', 'status' => 'published']);
        $pasta = Lunch::factory()->create(['caterer_id' => $caterer->id, 'caterer_meal_id' => CatererMeal::create(['caterer_id' => $caterer->id, 'name' => 'Spaghetti', 'price' => 10])->id, 'date' => '2026-10-21', 'status' => 'draft']);

        Livewire::test(ListLunches::class)
            ->searchTable('Nasi')
            ->assertCanSeeTableRecords([$nasi])
            ->assertCanNotSeeTableRecords([$pasta]);

        Livewire::test(ListLunches::class)
            ->filterTable('status', 'draft')
            ->assertCanSeeTableRecords([$pasta])
            ->assertCanNotSeeTableRecords([$nasi]);
    }

    public function test_member_categories_can_be_searched(): void
    {
        $junior = MemberCategory::create(['name' => 'Grade 7-11', 'default_fee' => 15]);
        $senior = MemberCategory::create(['name' => 'Grade 21-22', 'default_fee' => 60]);

        Livewire::test(ListMemberCategories::class)
            ->searchTable('21')
            ->assertCanSeeTableRecords([$senior])
            ->assertCanNotSeeTableRecords([$junior]);
    }

    public function test_members_can_be_filtered_by_account_status(): void
    {
        $claimed = User::factory()->create(['activated_at' => now()]);
        $unclaimed = User::factory()->create(['activated_at' => null]);

        Livewire::test(ListUsers::class)
            ->filterTable('account_status', 'unclaimed')
            ->assertCanSeeTableRecords([$unclaimed])
            ->assertCanNotSeeTableRecords([$claimed, $this->admin]);

        Livewire::test(ListUsers::class)
            ->filterTable('account_status', 'claimed')
            ->assertCanSeeTableRecords([$claimed])
            ->assertCanNotSeeTableRecords([$unclaimed, $this->admin]);

        Livewire::test(ListUsers::class)
            ->filterTable('account_status', 'admin')
            ->assertCanSeeTableRecords([$this->admin])
            ->assertCanNotSeeTableRecords([$claimed, $unclaimed]);
    }
}
