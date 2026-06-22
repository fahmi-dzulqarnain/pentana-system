<?php

namespace Tests\Feature;

use App\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ViewFeePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_proof_renders_as_a_clickable_zoomable_image(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $proof = PaymentProof::create([
            'user_id' => $member->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => 20,
            'status' => 'pending',
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->assertSuccessful()
            ->assertSee('receipts/test.jpg')        // the receipt image is rendered
            ->assertSee('cursor-zoom-in', false);   // the clickable lightbox thumbnail
    }
}
