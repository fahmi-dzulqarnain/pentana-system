<?php

namespace Tests\Feature\Receipts;

use App\Models\Payment\PaymentProof;
use App\Models\Payment\PaymentReceipt;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentReceiptModelTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create(['name' => 'Banmathi']);
    }

    private function proof(): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => $this->member->id,
            'proof_image_path' => 'receipts/r.jpg',
            'amount_claimed' => 60,
            'status' => 'verified',
        ]);
    }

    private function receipt(PaymentProof $proof, array $extra = []): PaymentReceipt
    {
        return PaymentReceipt::create([
            'number' => 'PEN-2026-0001',
            'year' => 2026,
            'sequence' => 1,
            'user_id' => $this->member->id,
            'payment_proof_id' => $proof->id,
            'member_name' => 'Banmathi',
            'member_email' => $this->member->email,
            'amount_received' => 60,
            'lines' => [
                ['label' => 'Monthly fee', 'month' => '2026-09-01', 'amount' => '30.00'],
                ['label' => 'Monthly fee', 'month' => '2026-10-01', 'amount' => '30.00'],
            ],
            'verified_at' => now(),
            'voided_at' => null,
        ] + $extra);
    }

    public function test_receipt_round_trips_its_snapshot(): void
    {
        $proof = $this->proof();
        $receipt = $this->receipt($proof)->fresh();

        $this->assertSame('30.00', $receipt->lines[1]['amount']);
        $this->assertSame('60.00', $receipt->amount_received);
        $this->assertFalse($receipt->isVoid());
        $this->assertSame('PEN-2026-0001.pdf', $receipt->filename());
        $this->assertTrue($proof->receipt->is($receipt));
        $this->assertTrue($receipt->paymentProof->is($proof));
        $this->assertTrue($receipt->user->is($this->member));
    }

    public function test_deleting_the_proof_row_keeps_the_receipt(): void
    {
        $receipt = $this->receipt($this->proof());

        DB::table('payment_proofs')->delete();

        $this->assertNotNull($receipt->fresh());
        $this->assertNull($receipt->fresh()->payment_proof_id);
    }

    public function test_two_receipts_cannot_share_a_proof(): void
    {
        $proof = $this->proof();
        $this->receipt($proof);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->receipt($proof, ['number' => 'PEN-2026-0002', 'sequence' => 2]);
    }
}
