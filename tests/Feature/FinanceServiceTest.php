<?php

namespace Tests\Feature;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceServiceTest extends TestCase
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

    private function pendingProof(User $user, float $claimed): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => $claimed,
            'status' => 'pending',
        ]);
    }

    private function service(): FinanceService
    {
        return app(FinanceService::class);
    }

    public function test_exact_payment_marks_bill_paid_with_no_credit(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-01-01', 100);
        $proof = $this->pendingProof($user, 100);

        $this->service()->verifyAndAllocate($proof, 100);

        $this->assertSame('verified', $proof->fresh()->status);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertEquals(100, $bill->allocations()->sum('amount_allocated'));
        $this->assertSame(0.0, $user->fresh()->credit);
    }

    public function test_surplus_becomes_credit_held_against_the_proof(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-01-01', 100);
        $proof = $this->pendingProof($user, 150);

        $this->service()->verifyAndAllocate($proof, 150);

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(50.0, $user->fresh()->credit);
    }

    public function test_payment_fills_oldest_bills_first(): void
    {
        $user = User::factory()->create();
        $jan = $this->bill($user, '2026-01-01', 100);
        $feb = $this->bill($user, '2026-02-01', 100);
        $proof = $this->pendingProof($user, 150);

        $this->service()->verifyAndAllocate($proof, 150);

        $this->assertSame('paid', $jan->fresh()->status);
        $this->assertSame('partial', $feb->fresh()->status);
        $this->assertEquals(100, $jan->allocations()->sum('amount_allocated'));
        $this->assertEquals(50, $feb->allocations()->sum('amount_allocated'));
        $this->assertSame(0.0, $user->fresh()->credit);
    }

    public function test_underpayment_marks_bill_partial(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-01-01', 100);
        $proof = $this->pendingProof($user, 60);

        $this->service()->verifyAndAllocate($proof, 60);

        $this->assertSame('partial', $bill->fresh()->status);
        $this->assertEquals(60, $bill->allocations()->sum('amount_allocated'));
    }

    public function test_existing_credit_is_applied_to_new_outstanding_bills(): void
    {
        $user = User::factory()->create();
        // Member overpays an old bill, leaving 50 credit held on the proof.
        $this->bill($user, '2026-01-01', 100);
        $proof = $this->pendingProof($user, 150);
        $this->service()->verifyAndAllocate($proof, 150);
        $this->assertSame(50.0, $user->fresh()->credit);

        // A new bill appears; existing credit should auto-cover part of it.
        $feb = $this->bill($user, '2026-02-01', 100);
        $this->service()->applyAvailableFunds($user->fresh());

        $this->assertSame('partial', $feb->fresh()->status);
        $this->assertEquals(50, $feb->allocations()->sum('amount_allocated'));
        $this->assertSame(0.0, $user->fresh()->credit);
    }

    public function test_outstanding_sums_unpaid_remainders_and_clamps_overpaid_bills(): void
    {
        $user = User::factory()->create();
        $this->bill($user, '2026-01-01', 100);
        $this->bill($user, '2026-02-01', 50);

        // Verify a 30 payment; allocation lands on the oldest bill (2026-01).
        $proof = $this->pendingProof($user, 30);
        $this->service()->verifyAndAllocate($proof, 30.0);

        $bills = FeePeriod::forMember($user->id)->get();

        // 100 - 30 = 70 outstanding on the first bill, 50 untouched on the second.
        $this->assertSame(120.0, FinanceService::outstanding($bills));
    }

    public function test_outstanding_is_zero_for_fully_paid_bills(): void
    {
        $user = User::factory()->create();
        $this->bill($user, '2026-01-01', 100);

        $proof = $this->pendingProof($user, 100);
        $this->service()->verifyAndAllocate($proof, 100.0);

        $this->assertSame(0.0, FinanceService::outstanding(FeePeriod::forMember($user->id)->get()));
    }
}
