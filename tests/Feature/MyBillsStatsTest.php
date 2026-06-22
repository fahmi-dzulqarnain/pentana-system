<?php

namespace Tests\Feature;

use App\Filament\Widgets\MyBillsStats;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Services\FinanceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyBillsStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_widget_is_visible_to_members_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertTrue(MyBillsStats::canView());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertFalse(MyBillsStats::canView());
    }

    public function test_stats_reflect_outstanding_credit_and_paid_to_date(): void
    {
        $member = User::factory()->create();
        FeePeriod::create([
            'user_id' => $member->id,
            'month_date' => '2026-06-01',
            'amount_due' => 100,
            'status' => 'unpaid',
        ]);
        $proof = PaymentProof::create([
            'user_id' => $member->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => 30,
            'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, 30);

        $this->actingAs($member);
        $widget = new MyBillsStats;

        $this->assertSame(70.0, $widget->getOutstanding());
        $this->assertSame(30.0, $widget->getPaidToDate());
        $this->assertSame(0.0, $widget->getCredit());
    }

    public function test_stats_render_for_a_member(): void
    {
        $member = User::factory()->create();
        FeePeriod::create([
            'user_id' => $member->id,
            'month_date' => '2026-06-01',
            'amount_due' => 100,
            'status' => 'unpaid',
        ]);

        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(MyBillsStats::class)
            ->assertSuccessful()
            ->assertSee('Outstanding')
            ->assertSee('MYR 100.00')
            ->assertSee('Paid to date');
    }
}
