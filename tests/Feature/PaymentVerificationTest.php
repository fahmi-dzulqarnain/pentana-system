<?php

namespace Tests\Feature;

use App\Filament\Resources\FeePayments\Pages\ListFeePayments;
use App\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use App\Models\Payment\PaymentProof;
use App\Models\Payment\PaymentReceipt;
use App\Models\User;
use App\Notifications\PaymentVerified;
use App\Services\FinanceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $this->member = User::factory()->create(['name' => 'Banmathi']);
    }

    private function proof(string $status = 'pending', array $extra = []): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => $this->member->id,
            'proof_image_path' => 'receipts/r.jpg',
            'amount_claimed' => 30,
            'status' => $status,
        ] + $extra);
    }

    public function test_admin_verifies_from_the_payment_page(): void
    {
        $proof = $this->proof(extra: ['member_note' => 'For October']);
        $this->actingAs(User::factory()->treasurer()->create());

        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->assertSee('For October')
            ->assertActionVisible('verifyPayment')
            ->assertActionVisible('rejectPayment')
            ->callAction('verifyPayment', data: ['actual_amount' => 25])
            ->assertHasNoActionErrors()
            ->assertNotified('Payment verified');

        $this->assertSame('verified', $proof->fresh()->status);
        $this->assertEquals(25, $proof->fresh()->amount_verified);
    }

    public function test_verifying_a_proof_someone_else_already_reviewed_warns_instead_of_failing(): void
    {
        Notification::fake();
        $proof = $this->proof();
        $this->actingAs(User::factory()->treasurer()->create());

        $page = Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->assertActionVisible('verifyPayment');

        // Another admin verifies it mid-request, after this action passed its visibility check
        // (each Livewire request reloads the record, so flipping it earlier just hides the action).
        $this->partialMock(FinanceService::class, function ($mock) use ($proof) {
            $mock->shouldReceive('verifyAndAllocate')->once()->andReturnUsing(function (...$args) use ($proof) {
                PaymentProof::whereKey($proof->id)->update(['status' => 'verified', 'amount_verified' => 30]);

                return (new FinanceService)->verifyAndAllocate(...$args);
            });
        });

        $page->callAction('verifyPayment', data: ['actual_amount' => 30])
            ->assertNotified('This payment was already reviewed.')
            ->assertNotNotified('Payment verified');

        $this->assertSame(0, PaymentReceipt::count());
        Notification::assertNotSentTo($this->member, PaymentVerified::class);
    }

    public function test_admin_rejects_with_a_reason_that_is_shown_afterwards(): void
    {
        $proof = $this->proof();
        $this->actingAs(User::factory()->treasurer()->create());

        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->callAction('rejectPayment', data: ['admin_note' => ''])
            ->assertHasActionErrors(['admin_note' => 'required']);

        $this->assertSame('pending', $proof->fresh()->status);

        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->callAction('rejectPayment', data: ['admin_note' => 'Receipt is unreadable'])
            ->assertNotified('Payment rejected');

        $this->assertSame('rejected', $proof->fresh()->status);

        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->assertSee('Receipt is unreadable')
            ->assertActionHidden('verifyPayment');
    }

    public function test_members_cannot_review_their_own_proof(): void
    {
        $proof = $this->proof();
        $this->actingAs($this->member);

        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->assertActionHidden('verifyPayment')
            ->assertActionHidden('rejectPayment');
    }

    public function test_admin_list_opens_on_the_pending_queue_with_text_statuses(): void
    {
        $pending = $this->proof();
        $verified = $this->proof('verified');
        $this->actingAs(User::factory()->treasurer()->create());

        Livewire::test(ListFeePayments::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$verified])
            ->assertSee('Pending')
            ->callTableAction('verifyPayment', $pending, data: ['actual_amount' => 30])
            ->assertHasNoTableActionErrors();

        $this->assertSame('verified', $pending->fresh()->status);
    }

    public function test_members_see_their_whole_history(): void
    {
        $pending = $this->proof();
        $verified = $this->proof('verified');
        $this->actingAs($this->member);

        Livewire::test(ListFeePayments::class)
            ->assertCanSeeTableRecords([$pending, $verified]);
    }
}
