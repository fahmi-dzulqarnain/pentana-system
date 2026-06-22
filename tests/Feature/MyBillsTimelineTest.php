<?php

namespace Tests\Feature;

use App\Filament\Widgets\MyBillsTimeline;
use App\Models\Payment\FeePeriod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyBillsTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function bill(User $user, string $monthDate, float $amount): FeePeriod
    {
        return FeePeriod::create([
            'user_id' => $user->id,
            'month_date' => $monthDate,
            'amount_due' => $amount,
            'status' => 'unpaid',
        ]);
    }

    public function test_widget_is_visible_to_members_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertTrue(MyBillsTimeline::canView());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertFalse(MyBillsTimeline::canView());
    }

    public function test_widget_shows_only_the_members_own_bills(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $this->bill($member, '2026-06-01', 100);
        $this->bill($other, '2026-06-01', 250);

        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(MyBillsTimeline::class)
            ->assertSuccessful()
            ->assertSee('June 2026')
            ->assertSee('100.00')
            ->assertDontSee('250.00');
    }
}
