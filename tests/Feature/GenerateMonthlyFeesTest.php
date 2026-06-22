<?php

namespace Tests\Feature;

use App\Models\MemberCategory;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateMonthlyFeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_bills_the_members_custom_fee_over_the_category_default(): void
    {
        $category = MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 100]);
        $user = User::factory()->create([
            'member_category_id' => $category->id,
            'custom_fee' => 75,
        ]);

        $this->artisan('app:generate-monthly-fees')->assertExitCode(0);

        $bill = FeePeriod::where('user_id', $user->id)->first();
        $this->assertNotNull($bill);
        $this->assertEquals(75, $bill->amount_due);
    }

    public function test_it_bills_the_category_default_when_no_custom_fee(): void
    {
        $category = MemberCategory::create(['name' => 'Grade 11-12', 'default_fee' => 120]);
        $user = User::factory()->create([
            'member_category_id' => $category->id,
            'custom_fee' => null,
        ]);

        $this->artisan('app:generate-monthly-fees')->assertExitCode(0);

        $bill = FeePeriod::where('user_id', $user->id)->first();
        $this->assertEquals(120, $bill->amount_due);
    }

    public function test_existing_credit_pays_the_newly_generated_bill(): void
    {
        $category = MemberCategory::create(['name' => 'Grade 13-15', 'default_fee' => 50]);
        $user = User::factory()->create(['member_category_id' => $category->id]);

        // Member holds 50 credit from a verified, unallocated proof.
        PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/x.jpg',
            'amount_claimed' => 50,
            'amount_verified' => 50,
            'status' => 'verified',
        ]);
        $this->assertSame(50.0, $user->fresh()->credit);

        $this->artisan('app:generate-monthly-fees')->assertExitCode(0);

        $bill = FeePeriod::where('user_id', $user->id)->first();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(0.0, $user->fresh()->credit);
    }
}
