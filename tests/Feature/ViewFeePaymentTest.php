<?php

namespace Tests\Feature;

use App\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ViewFeePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_thumbnail_is_clickable_with_a_view_action(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('receipts/test.jpg', 'fake-image-bytes');

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
            ->assertSee('receipts/test.jpg')                            // thumbnail rendered
            ->assertInfolistActionExists('proof_image_path', 'viewReceipt'); // click opens the modal action
    }

    public function test_receipt_modal_renders_the_full_image_and_zoom_link(): void
    {
        $html = view('filament.infolists.receipt-modal', [
            'url' => '/storage/receipts/test.jpg',
        ])->render();

        $this->assertStringContainsString('/storage/receipts/test.jpg', $html);
        $this->assertStringContainsString('Open original', $html);
    }
}
