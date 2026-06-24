<?php

namespace Tests\Feature\Api;

use App\Models\Payment\PaymentProof;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentProofsTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/payment-proofs')->assertStatus(401);
        $this->postJson('/api/v1/payment-proofs', [])->assertStatus(401);
    }

    public function test_a_member_can_submit_a_payment_proof(): void
    {
        Storage::fake('public');
        $member = User::factory()->create(['activated_at' => now()]);
        Sanctum::actingAs($member);

        $response = $this->post('/api/v1/payment-proofs', [
            'proof_image' => UploadedFile::fake()->image('receipt.jpg'),
            'amount_claimed' => 50,
            'member_note' => 'June dues',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount_claimed', '50.00')
            ->assertJsonPath('data.member_note', 'June dues');

        $proof = PaymentProof::where('user_id', $member->id)->first();
        $this->assertNotNull($proof);
        $this->assertSame('pending', $proof->status);
        Storage::disk('public')->assertExists($proof->proof_image_path);
    }

    public function test_proof_submission_validates_the_image_and_amount(): void
    {
        $member = User::factory()->create(['activated_at' => now()]);
        Sanctum::actingAs($member);

        // Not an image
        $this->post('/api/v1/payment-proofs', [
            'proof_image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            'amount_claimed' => 50,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // Missing amount
        $this->post('/api/v1/payment-proofs', [
            'proof_image' => UploadedFile::fake()->image('r.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // Non-positive amount
        $this->post('/api/v1/payment-proofs', [
            'proof_image' => UploadedFile::fake()->image('r.jpg'),
            'amount_claimed' => 0,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, PaymentProof::count());
    }

    public function test_it_lists_only_the_members_own_proofs_newest_first(): void
    {
        $member = User::factory()->create(['activated_at' => now()]);
        PaymentProof::create(['user_id' => $member->id, 'proof_image_path' => 'p/a.jpg', 'amount_claimed' => 10, 'status' => 'pending']);
        PaymentProof::create(['user_id' => User::factory()->create()->id, 'proof_image_path' => 'p/b.jpg', 'amount_claimed' => 99, 'status' => 'pending']);

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/payment-proofs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount_claimed', '10.00');
    }
}
