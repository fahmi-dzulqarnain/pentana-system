<?php

namespace Tests\Feature;

use App\Filament\Resources\FeePayments\Pages\EditFeePayment;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Services\FinanceService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentDeletionTest extends TestCase
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

    private function verifiedProof(User $user, float $amount): PaymentProof
    {
        $proof = PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => $amount,
            'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, $amount);

        return $proof->fresh();
    }

    public function test_deleting_a_payment_reopens_the_bills_it_paid(): void
    {
        $member = User::factory()->create();
        $sep = $this->bill($member, '2026-09-01', 20);
        $oct = $this->bill($member, '2026-10-01', 20);
        $proof = $this->verifiedProof($member, 40);
        $this->assertSame('paid', $oct->fresh()->status);

        $proof->delete();

        $this->assertSame('unpaid', $sep->fresh()->status);
        $this->assertSame('unpaid', $oct->fresh()->status);
    }

    public function test_remaining_credit_is_reapplied_after_a_payment_is_deleted(): void
    {
        $member = User::factory()->create();
        $sep = $this->bill($member, '2026-09-01', 20);
        $oct = $this->bill($member, '2026-10-01', 20);
        $first = $this->verifiedProof($member, 30);   // Sep paid, Oct partial 10
        $second = $this->verifiedProof($member, 30);  // Oct paid, 20 credit left

        $first->delete();

        // The second payment's MYR 30 is reallocated oldest-first: September in full, MYR 10 to October.
        $this->assertSame('paid', $sep->fresh()->status);
        $this->assertSame('partial', $oct->fresh()->status);
        $this->assertEquals(10, $oct->fresh()->allocations()->sum('amount_allocated'));
    }

    public function test_the_admin_delete_action_reopens_the_bills(): void
    {
        $member = User::factory()->create();
        $bill = $this->bill($member, '2026-10-01', 20);
        $proof = $this->verifiedProof($member, 20);

        $this->actingAs(User::factory()->treasurer()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditFeePayment::class, ['record' => $proof->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($proof);
        $this->assertSame('unpaid', $bill->fresh()->status);
    }
}
