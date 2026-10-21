<?php

namespace Tests\Feature\Receipts;

use App\Filament\Resources\FeePayments\FeePaymentResource;
use App\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use App\Filament\Resources\PaymentReceipts\Pages\ListPaymentReceipts;
use App\Filament\Resources\PaymentReceipts\PaymentReceiptResource;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\Payment\PaymentReceipt;
use App\Models\User;
use App\Services\FinanceService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $this->travelTo(Carbon::parse('2026-10-21 10:00'));
        $this->member = User::factory()->create(['name' => 'Banmathi']);
    }

    private function pending(?User $user = null): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => ($user ?? $this->member)->id,
            'proof_image_path' => 'receipts/r.jpg',
            'amount_claimed' => 30,
            'status' => 'pending',
        ]);
    }

    private function verified(?User $user = null): PaymentProof
    {
        $user ??= $this->member;
        FeePeriod::create([
            'user_id' => $user->id, 'month_date' => '2026-10-01',
            'amount_due' => 30, 'status' => 'unpaid',
        ]);
        $proof = $this->pending($user);
        app(FinanceService::class)->verifyAndAllocate($proof, 30);

        return $proof->fresh();
    }

    public function test_member_downloads_their_receipt(): void
    {
        $proof = $this->verified();
        $this->actingAs($this->member);

        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->assertActionVisible('downloadReceipt')
            ->callAction('downloadReceipt')
            ->assertFileDownloaded('PEN-2026-0001.pdf');
    }

    public function test_member_cannot_open_another_members_payment(): void
    {
        $proof = $this->verified(User::factory()->create());
        $this->actingAs($this->member);

        $this->get(FeePaymentResource::getUrl('view', ['record' => $proof]))->assertNotFound();
    }

    public function test_download_is_refused_for_a_non_owner(): void
    {
        $proof = $this->verified();
        $other = User::factory()->create();
        $this->actingAs($other);

        // The resource query scopes the record away, so the action is never reachable.
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()]);
    }

    public function test_no_action_without_a_receipt(): void
    {
        $proof = $this->pending();
        $this->actingAs($this->member);

        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->assertActionHidden('downloadReceipt');
    }

    public function test_treasurer_downloads_any_receipt(): void
    {
        $proof = $this->verified();
        $this->actingAs(User::factory()->treasurer()->create());

        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->assertActionVisible('downloadReceipt')
            ->callAction('downloadReceipt')
            ->assertFileDownloaded('PEN-2026-0001.pdf');
    }

    public function test_receipts_list_is_finance_only(): void
    {
        $proof = $this->verified();
        $voided = $proof->receipt;
        $voided->update(['voided_at' => now()]);
        $orphan = PaymentReceipt::create([
            'number' => 'PEN-2026-0002', 'year' => 2026, 'sequence' => 2,
            'user_id' => null, 'payment_proof_id' => null,
            'member_name' => 'Former Member', 'amount_received' => 20,
            'credit_used' => 0, 'credit_added' => 0,
            'lines' => [['label' => 'Monthly fee: October 2026', 'month' => '2026-10-01', 'amount' => '20.00']],
            'verified_at' => now(),
        ]);

        $this->actingAs($this->member);
        $this->get(PaymentReceiptResource::getUrl('index'))->assertForbidden();

        $this->actingAs(User::factory()->treasurer()->create());
        $this->get(PaymentReceiptResource::getUrl('index'))->assertOk();

        Livewire::test(ListPaymentReceipts::class)
            ->assertCanSeeTableRecords([$voided, $orphan])
            ->assertSee('Former Member')
            ->callTableAction('downloadReceipt', $orphan)
            ->assertFileDownloaded('PEN-2026-0002.pdf');
    }
}
