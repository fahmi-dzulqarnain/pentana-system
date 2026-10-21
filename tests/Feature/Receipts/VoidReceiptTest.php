<?php

namespace Tests\Feature\Receipts;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\Payment\PaymentReceipt;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class VoidReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create(['name' => 'Banmathi']);
    }

    private function verified(?User $user = null): PaymentProof
    {
        $user ??= $this->member;
        FeePeriod::create([
            'user_id' => $user->id,
            'month_date' => '2026-10-01',
            'amount_due' => 30,
            'status' => 'unpaid',
        ]);
        $proof = PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/r.jpg',
            'amount_claimed' => 30,
            'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, 30);

        return $proof->fresh();
    }

    public function test_deleting_a_verified_payment_voids_its_receipt(): void
    {
        $proof = $this->verified();
        $receiptId = $proof->receipt->id;
        $number = $proof->receipt->number;

        $proof->delete();

        $receipt = PaymentReceipt::findOrFail($receiptId);
        $this->assertNotNull($receipt->voided_at);
        $this->assertTrue($receipt->isVoid());
        $this->assertNull($receipt->payment_proof_id);
        $this->assertSame($number, $receipt->number);

        $next = $this->verified(User::factory()->create());
        $this->assertSame('PEN-'.$next->receipt->year.'-0002', $next->receipt->number);
    }

    public function test_deleting_a_member_keeps_their_receipts_valid(): void
    {
        $receipt = $this->verified()->receipt;

        $this->member->delete();

        $receipt = $receipt->fresh();
        $this->assertNotNull($receipt);
        $this->assertNull($receipt->voided_at);
        $this->assertNull($receipt->user_id);
        $this->assertSame('Banmathi', $receipt->member_name);
    }

    public function test_a_failed_release_keeps_the_payment_and_its_receipt_valid(): void
    {
        $proof = $this->verified();
        $receiptId = $proof->receipt->id;

        $this->partialMock(FinanceService::class, function ($mock) {
            $mock->shouldReceive('releasePayment')->andThrow(new RuntimeException('release failed'));
        });

        try {
            $proof->delete();
            $this->fail('Expected the delete to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('release failed', $e->getMessage());
        }

        $this->assertNotNull(PaymentProof::find($proof->id));
        $receipt = PaymentReceipt::findOrFail($receiptId);
        $this->assertNull($receipt->voided_at);
        $this->assertSame($proof->id, $receipt->payment_proof_id);
    }
}
