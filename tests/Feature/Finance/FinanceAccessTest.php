<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\FeePayments\FeePaymentResource;
use App\Filament\Resources\FeePayments\Pages\ListFeePayments;
use App\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use App\Filament\Resources\FeePeriods\FeePeriodResource;
use App\Filament\Widgets\NeedsAttention;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    private function proofFor(User $user, string $status = 'pending'): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'r.jpg',
            'amount_claimed' => 30,
            'status' => $status,
        ]);
    }

    public function test_plain_admin_cannot_open_fee_periods(): void
    {
        $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]))
            ->get(FeePeriodResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]))
            ->get(FeePeriodResource::getUrl('index'))
            ->assertOk();
    }

    public function test_plain_admin_sees_fee_payments_as_my_payments(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['name' => 'Other Member']);
        $mine = $this->proofFor($admin);
        $theirs = $this->proofFor($member);
        $this->actingAs($admin);

        $this->assertSame('My Payments', FeePaymentResource::getNavigationLabel());
        $this->assertTrue(FeePaymentResource::canCreate());

        Livewire::test(ListFeePayments::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_plain_admin_cannot_verify_a_payment(): void
    {
        $member = User::factory()->create();
        $proof = $this->proofFor($member);
        $this->actingAs(User::factory()->admin()->create());

        $this->assertFalse(FeePaymentResource::canEdit($proof));
        $this->assertFalse(FeePaymentResource::canDelete($proof));

        // Another member's proof is outside a plain admin's query, so it cannot even be opened.
        Livewire::test(ListFeePayments::class)->assertCanNotSeeTableRecords([$proof]);
        $this->get(FeePaymentResource::getUrl('edit', ['record' => $proof]))->assertNotFound();
        $this->get(FeePaymentResource::getUrl('view', ['record' => $proof]))->assertNotFound();

        // Even on their own pending proof, the review actions stay hidden.
        $own = $this->proofFor(auth()->user());
        Livewire::test(ViewFeePayment::class, ['record' => $own->getKey()])
            ->assertActionHidden('verifyPayment')
            ->assertActionHidden('rejectPayment');
    }

    public function test_needs_attention_hides_finance_tiles_from_plain_admins(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(NeedsAttention::class)
            ->assertDontSee('Payments to verify')
            ->assertDontSee('Unpaid bills this month')
            ->assertSee('Next lunch')
            ->assertSee('On waitlists');

        $this->actingAs(User::factory()->treasurer()->create());
        Livewire::test(NeedsAttention::class)
            ->assertSee('Payments to verify')
            ->assertSee('Next lunch')
            ->assertSee('On waitlists')
            ->assertSee('Unpaid bills this month');
    }

    public function test_global_search_hides_finance_records_from_plain_admins(): void
    {
        $member = User::factory()->create(['name' => 'Zed Searchable']);
        $this->proofFor($member);

        $this->actingAs(User::factory()->admin()->create());
        $this->assertCount(0, FeePaymentResource::getGlobalSearchResults('Zed'));

        $this->actingAs(User::factory()->treasurer()->create());
        $this->assertCount(1, FeePaymentResource::getGlobalSearchResults('Zed'));
    }
}
