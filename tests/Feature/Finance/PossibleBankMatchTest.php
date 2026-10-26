<?php

namespace Tests\Feature\Finance;

use App\Enums\StatementLineOutcome;
use App\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use App\Models\Payment\BankStatementLine;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PossibleBankMatchTest extends TestCase
{
    use RefreshDatabase;

    private function proofFor(User $member, string $status = 'pending'): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => $member->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => 30,
            'status' => $status,
        ]);
    }

    private function lineFor(User $member, array $attrs = []): BankStatementLine
    {
        return BankStatementLine::factory()->create($attrs + [
            'user_id' => $member->id,
            'txn_date' => now()->toDateString(),
            'amount' => '105.00',
            'bank_ref_no' => '800000001',
            'reference_text' => 'PTN member fee',
            'outcome' => StatementLineOutcome::NeedsReview,
        ]);
    }

    private function viewAs(User $viewer, PaymentProof $proof)
    {
        $this->actingAs($viewer);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        return Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()]);
    }

    public function test_pending_proof_shows_a_coded_line_with_a_different_amount(): void
    {
        $member = User::factory()->create();
        $proof = $this->proofFor($member);
        $this->lineFor($member);

        $this->viewAs(User::factory()->treasurer()->create(), $proof)
            ->assertSee('Possible bank match')
            ->assertSee('800000001')
            ->assertSee('105.00');
    }

    public function test_other_members_lines_are_not_shown(): void
    {
        $member = User::factory()->create();
        $proof = $this->proofFor($member);
        $this->lineFor(User::factory()->create());

        $this->viewAs(User::factory()->treasurer()->create(), $proof)
            ->assertDontSee('Possible bank match')
            ->assertDontSee('800000001');
    }

    public function test_member_viewing_their_own_proof_does_not_see_it(): void
    {
        $member = User::factory()->create();
        $proof = $this->proofFor($member);
        $this->lineFor($member);

        $this->viewAs($member, $proof)
            ->assertDontSee('Possible bank match')
            ->assertDontSee('800000001');
    }

    public function test_verified_proof_does_not_show_it(): void
    {
        $member = User::factory()->create();
        $proof = $this->proofFor($member, 'verified');
        $this->lineFor($member);

        $this->viewAs(User::factory()->treasurer()->create(), $proof)
            ->assertDontSee('Possible bank match');
    }

    public function test_matched_old_or_resolved_lines_are_excluded_and_newest_comes_first(): void
    {
        $member = User::factory()->create();
        $proof = $this->proofFor($member);
        $other = $this->proofFor($member, 'verified');

        $this->lineFor($member, ['bank_ref_no' => '800000010', 'txn_date' => now()->subDays(2)->toDateString()]);
        $this->lineFor($member, ['bank_ref_no' => '800000011', 'txn_date' => now()->toDateString(), 'outcome' => StatementLineOutcome::NoProof]);
        $this->lineFor($member, ['bank_ref_no' => '800000012', 'payment_proof_id' => $other->id]);
        $this->lineFor($member, ['bank_ref_no' => '800000013', 'txn_date' => now()->subDays(31)->toDateString()]);
        $this->lineFor($member, ['bank_ref_no' => '800000014', 'outcome' => StatementLineOutcome::AutoVerified]);

        $refs = BankStatementLine::possibleMatchesFor($proof)->pluck('bank_ref_no')->all();

        $this->assertSame(['800000011', '800000010'], $refs);
    }

    public function test_reference_text_is_escaped(): void
    {
        $member = User::factory()->create();
        $proof = $this->proofFor($member);
        $this->lineFor($member, ['reference_text' => '<script>alert(1)</script>']);

        $this->viewAs(User::factory()->treasurer()->create(), $proof)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
