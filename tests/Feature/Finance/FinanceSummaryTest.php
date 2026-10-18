<?php

namespace Tests\Feature\Finance;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentAllocation;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\FinanceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinanceSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function bill(User $user, string $month, float $due): FeePeriod
    {
        return FeePeriod::create([
            'user_id' => $user->id,
            'month_date' => $month,
            'amount_due' => $due,
            'status' => 'unpaid',
        ]);
    }

    private function proof(User $user, string $status, float $amount, $verifiedAt = null): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'r.jpg',
            'amount_claimed' => $amount,
            'amount_verified' => $status === 'verified' ? $amount : null,
            'status' => $status,
            'verified_at' => $status === 'verified' ? ($verifiedAt ?? now()) : null,
        ]);
    }

    private function row(User $user): User
    {
        return FinanceSummary::members()->whereKey($user->id)->firstOrFail();
    }

    public function test_owes_matches_finance_service_for_mixed_bills(): void
    {
        $user = User::factory()->create();
        $this->bill($user, '2026-06-01', 100);
        $partial = $this->bill($user, '2026-07-01', 50);
        $over = $this->bill($user, '2026-08-01', 30);

        $proof = $this->proof($user, 'verified', 60);
        PaymentAllocation::create(['payment_proof_id' => $proof->id, 'fee_period_id' => $partial->id, 'amount_allocated' => 20]);
        PaymentAllocation::create(['payment_proof_id' => $proof->id, 'fee_period_id' => $over->id, 'amount_allocated' => 40]);

        $row = $this->row($user);

        $expected = FinanceService::outstanding(FeePeriod::forMember($user->id)->get());
        $this->assertSame(130.0, $expected);
        $this->assertSame($expected, round((float) $row->owes, 2));
        $this->assertSame(2, (int) $row->unpaid_count);
        $this->assertSame('2026-06-01', substr((string) $row->oldest_unpaid, 0, 10));
    }

    public function test_credit_matches_user_credit_attribute(): void
    {
        $user = User::factory()->create();
        $this->bill($user, '2026-08-01', 100);
        $this->bill($user, '2026-09-01', 50);

        $proof = $this->proof($user, 'verified', 200);
        app(FinanceService::class)->applyAvailableFunds($user);

        $row = $this->row($user);

        $this->assertSame(50.0, $user->fresh()->credit);
        $this->assertSame($user->fresh()->credit, round((float) $row->credit, 2));
        $this->assertSame(0.0, round((float) $row->owes, 2));
        $this->assertSame(0, (int) $row->unpaid_count);
        $this->assertNull($row->oldest_unpaid);
    }

    public function test_pending_and_rejected_proofs_count_nowhere(): void
    {
        $user = User::factory()->create();
        $this->proof($user, 'pending', 80);
        $this->proof($user, 'rejected', 60);

        $row = $this->row($user);
        $totals = FinanceSummary::totals();

        $this->assertSame(0.0, round((float) $row->credit, 2));
        $this->assertSame(0.0, $totals['credit']);
        $this->assertSame(0.0, $totals['collected_all_time']);
        $this->assertSame(0.0, $totals['collected_this_month']);
        $this->assertSame(1, $totals['pending_count']);
    }

    public function test_disabled_members_are_excluded_from_members_and_outstanding_but_counted_in_collected(): void
    {
        $active = User::factory()->create();
        $disabled = User::factory()->create(['disabled_at' => now()]);

        $this->bill($active, '2026-09-01', 40);
        $this->bill($disabled, '2026-09-01', 70);
        $this->proof($disabled, 'verified', 25);

        $this->assertFalse(FinanceSummary::members()->whereKey($disabled->id)->exists());
        $this->assertTrue(FinanceSummary::members()->whereKey($active->id)->exists());

        $totals = FinanceSummary::totals();
        $this->assertSame(40.0, $totals['outstanding']);
        $this->assertSame(1, $totals['owing_members']);
        $this->assertSame(25.0, $totals['collected_this_month']);
        $this->assertSame(25.0, $totals['collected_all_time']);
        $this->assertSame(0.0, $totals['credit']);
    }

    public function test_totals(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        // A: owes 100 + 30 = 130.
        $a = User::factory()->create();
        $this->bill($a, '2026-09-01', 100);
        $this->bill($a, '2026-10-01', 30);

        // B: paid this month's bill in full and holds 15.50 credit.
        $b = User::factory()->create();
        $this->bill($b, '2026-10-01', 30);
        $this->proof($b, 'verified', 45.50, now());
        app(FinanceService::class)->applyAvailableFunds($b);

        // C: owes 20 after a partial payment verified last month, plus a pending proof.
        $c = User::factory()->create();
        $this->bill($c, '2026-09-01', 50);
        $this->proof($c, 'verified', 30, Carbon::parse('2026-09-20 09:00:00'));
        app(FinanceService::class)->applyAvailableFunds($c);

        Carbon::setTestNow('2026-10-02 08:00:00');
        $this->proof($c, 'pending', 20);
        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->proof($a, 'pending', 130);
        Carbon::setTestNow('2026-10-19 10:00:00');

        // Members owing nothing are still listed.
        $nobody = User::factory()->create();
        $this->assertTrue(FinanceSummary::members()->whereKey($nobody->id)->exists());

        $totals = FinanceSummary::totals();

        $this->assertSame(150.0, $totals['outstanding']);
        $this->assertSame(2, $totals['owing_members']);
        $this->assertSame(45.5, $totals['collected_this_month']);
        $this->assertSame(75.5, $totals['collected_all_time']);
        $this->assertSame(15.5, $totals['credit']);
        $this->assertSame(1, $totals['credit_members']);
        $this->assertSame(2, $totals['pending_count']);
        $this->assertInstanceOf(Carbon::class, $totals['oldest_pending_at']);
        $this->assertSame('2026-10-02 08:00:00', $totals['oldest_pending_at']->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_oldest_pending_at_is_null_without_pending_proofs(): void
    {
        $this->assertNull(FinanceSummary::totals()['oldest_pending_at']);
    }

    public function test_paid_this_month_flag(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        $paid = User::factory()->create();
        $this->bill($paid, '2026-10-01', 30);
        $this->proof($paid, 'verified', 30);
        app(FinanceService::class)->applyAvailableFunds($paid);

        $partial = User::factory()->create();
        $this->bill($partial, '2026-10-01', 30);
        $this->proof($partial, 'verified', 10);
        app(FinanceService::class)->applyAvailableFunds($partial);

        $noBill = User::factory()->create();
        $paidLastMonth = User::factory()->create();
        $bill = $this->bill($paidLastMonth, '2026-09-01', 30);
        $bill->update(['status' => 'paid']);

        $this->assertTrue((bool) (int) $this->row($paid)->paid_this_month);
        $this->assertFalse((bool) (int) $this->row($partial)->paid_this_month);
        $this->assertFalse((bool) (int) $this->row($noBill)->paid_this_month);
        $this->assertFalse((bool) (int) $this->row($paidLastMonth)->paid_this_month);

        Carbon::setTestNow();
    }

    public function test_members_eager_loads_member_category(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->row($user)->relationLoaded('memberCategory'));
    }
}
