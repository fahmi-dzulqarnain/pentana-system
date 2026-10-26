<?php

namespace Tests\Feature\Finance;

use App\Enums\StatementLineOutcome;
use App\Models\Payment\BankStatementImport;
use App\Models\Payment\BankStatementLine;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementLineTest extends TestCase
{
    use RefreshDatabase;

    private function proof(User $user): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'proofs/x.jpg',
            'amount_claimed' => 30,
            'amount_verified' => 30,
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    public function test_a_line_is_unique_by_date_ref_and_amount(): void
    {
        $row = BankStatementLine::factory()->make([
            'txn_date' => '2026-10-01', 'bank_ref_no' => 'R1', 'amount' => '30.00',
        ])->getAttributes();
        $import = BankStatementImport::factory()->create();
        $row['bank_statement_import_id'] = $import->id;
        $row['outcome'] = StatementLineOutcome::NoCode->value;
        $row['created_at'] = $row['updated_at'] = now();

        BankStatementLine::insertOrIgnore($row);
        BankStatementLine::insertOrIgnore($row);

        $this->assertSame(1, BankStatementLine::count());
    }

    public function test_deleting_an_auto_verified_proof_puts_its_line_back_in_review(): void
    {
        $user = User::factory()->create();
        $proof = $this->proof($user);
        $line = BankStatementLine::factory()->create([
            'user_id' => $user->id,
            'payment_proof_id' => $proof->id,
            'outcome' => StatementLineOutcome::AutoVerified,
        ]);

        $proof->delete();

        $line->refresh();
        $this->assertNull($line->payment_proof_id);
        $this->assertSame(StatementLineOutcome::NeedsReview, $line->outcome);
    }

    public function test_a_proof_can_be_linked_to_only_one_line(): void
    {
        $proof = $this->proof(User::factory()->create());
        BankStatementLine::factory()->create(['payment_proof_id' => $proof->id]);

        $this->expectException(QueryException::class);
        BankStatementLine::factory()->create(['payment_proof_id' => $proof->id]);
    }
}
