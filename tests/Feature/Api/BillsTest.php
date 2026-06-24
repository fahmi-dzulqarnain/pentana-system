<?php

namespace Tests\Feature\Api;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bills_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/bills')->assertStatus(401);
        $this->getJson('/api/v1/bills/summary')->assertStatus(401);
    }

    public function test_it_lists_only_the_members_own_bills_with_paid_and_outstanding(): void
    {
        $member = User::factory()->create();
        FeePeriod::create(['user_id' => $member->id, 'month_date' => '2026-06-01', 'amount_due' => 100, 'status' => 'unpaid']);
        $proof = PaymentProof::create([
            'user_id' => $member->id, 'proof_image_path' => 'receipts/a.jpg', 'amount_claimed' => 30, 'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, 30);

        // Another member's bill must NOT leak through.
        $other = User::factory()->create();
        FeePeriod::create(['user_id' => $other->id, 'month_date' => '2026-06-01', 'amount_due' => 500, 'status' => 'unpaid']);

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/bills')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.month', '2026-06')
            ->assertJsonPath('data.0.amount_due', '100.00')
            ->assertJsonPath('data.0.amount_paid', '30.00')
            ->assertJsonPath('data.0.outstanding', '70.00')
            // A part-paid bill is 'partial' (FinanceService recomputes status on allocation).
            ->assertJsonPath('data.0.status', 'partial');
    }

    public function test_summary_totals_outstanding_and_credit(): void
    {
        $member = User::factory()->create();
        FeePeriod::create(['user_id' => $member->id, 'month_date' => '2026-05-01', 'amount_due' => 100, 'status' => 'unpaid']);
        FeePeriod::create(['user_id' => $member->id, 'month_date' => '2026-06-01', 'amount_due' => 50, 'status' => 'unpaid']);
        $proof = PaymentProof::create([
            'user_id' => $member->id, 'proof_image_path' => 'receipts/b.jpg', 'amount_claimed' => 30, 'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, 30);

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/bills/summary')
            ->assertOk()
            ->assertJsonPath('data.total_outstanding', '120.00')
            ->assertJsonPath('data.bill_count', 2)
            ->assertJsonPath('data.unpaid_count', 2);
    }
}
