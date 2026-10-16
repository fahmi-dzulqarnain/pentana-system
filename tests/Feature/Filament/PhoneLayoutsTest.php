<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LunchCalendar;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Lunch;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PhoneLayoutsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name' => 'Zara Admin', 'activated_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    public function test_calendar_has_a_phone_agenda_of_working_days_and_planned_weekends(): void
    {
        $this->travelTo('2026-10-16 09:00');                       // a Friday in October 2026
        Lunch::factory()->create(['date' => '2026-10-17']);        // a Saturday with a lunch

        $html = Livewire::test(LunchCalendar::class)->html();

        $this->assertStringContainsString('lc-agenda', $html);
        preg_match('/<[^>]*lc-agenda"[^>]*>(.*)$/s', $html, $agenda);
        preg_match_all('/lc-agenda__day[" ]/', $agenda[1], $days);

        // October 2026 has 22 weekdays; the Saturday appears because it has a lunch, Sunday the 18th does not.
        $this->assertCount(23, $days[0]);
        $this->assertStringContainsString('lc-agenda__day--today', $agenda[1]);
    }

    public function test_calendar_stats_and_grid_collapse_on_phones(): void
    {
        $html = Livewire::test(LunchCalendar::class)->html();

        $this->assertStringContainsString('@media (max-width: 639px)', $html);
        $this->assertMatchesRegularExpression('/\.lc-grid \{ display:none; \}/', $html);
        $this->assertStringContainsString('class="lc-stats"', $html);
    }

    public function test_members_list_shows_category_and_account_status_sorted_by_name(): void
    {
        $unclaimed = User::factory()->create(['name' => 'Aisyah', 'activated_at' => null]);
        $claimed = User::factory()->create(['name' => 'Banmathi', 'activated_at' => now()]);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$unclaimed, $claimed, User::where('name', 'Zara Admin')->first()], inOrder: true)
            ->assertSee('Not claimed yet')
            ->assertSee('Claimed')
            ->assertSee('Admin')
            ->assertCanNotRenderTableColumn('email_verified_at');
    }

    public function test_every_table_stacks_into_cards_on_phones(): void
    {
        $table = Livewire::test(ListUsers::class)->instance()->getTable();

        $this->assertTrue($table->isStackedOnMobile());
    }
}
