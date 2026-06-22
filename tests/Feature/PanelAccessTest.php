<?php

namespace Tests\Feature;

use App\Filament\Resources\FeePayments\FeePaymentResource;
use App\Filament\Resources\FeePayments\Pages\ListFeePayments;
use App\Filament\Resources\FeePeriods\FeePeriodResource;
use App\Filament\Resources\MemberCategories\MemberCategoryResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentAllocation;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function proofFor(User $user): PaymentProof
    {
        return PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => 10,
            'status' => 'pending',
        ]);
    }

    public function test_members_cannot_access_admin_only_resources(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(UserResource::canAccess());
        $this->assertFalse(MemberCategoryResource::canAccess());
    }

    public function test_admins_can_access_admin_only_resources(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue(UserResource::canAccess());
        $this->assertTrue(MemberCategoryResource::canAccess());
    }

    public function test_members_cannot_access_fee_periods(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(FeePeriodResource::canAccess());
    }

    public function test_admins_can_access_fee_periods(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue(FeePeriodResource::canAccess());
    }

    public function test_members_only_see_their_own_payment_proofs(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->proofFor($alice);
        $this->proofFor($bob);

        $this->actingAs($alice);
        $userIds = FeePaymentResource::getEloquentQuery()->pluck('user_id')->unique()->values()->all();

        $this->assertSame([$alice->id], $userIds);
    }

    public function test_admins_see_every_payment_proof(): void
    {
        $this->proofFor(User::factory()->create());
        $this->proofFor(User::factory()->create());

        $this->actingAs(User::factory()->admin()->create());

        $this->assertSame(2, FeePaymentResource::getEloquentQuery()->count());
    }

    public function test_members_can_still_submit_payment_proofs(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertTrue(FeePaymentResource::canCreate());
    }

    public function test_members_cannot_verify_payments(): void
    {
        $member = User::factory()->create();
        $proof = $this->proofFor($member);

        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListFeePayments::class)
            ->assertTableActionHidden('Verify Payment', $proof);
    }

    public function test_admins_can_verify_pending_payments(): void
    {
        $admin = User::factory()->admin()->create();
        $proof = $this->proofFor(User::factory()->create());

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListFeePayments::class)
            ->assertTableActionVisible('Verify Payment', $proof);
    }

    public function test_admin_users_pages_render_with_derived_credit(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        // A verified, unallocated proof gives the member real credit to display.
        PaymentProof::create([
            'user_id' => $member->id,
            'proof_image_path' => 'receipts/x.jpg',
            'amount_claimed' => 50,
            'amount_verified' => 50,
            'status' => 'verified',
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListUsers::class)->assertSuccessful();
        Livewire::test(ViewUser::class, ['record' => $member->getKey()])->assertSuccessful();
    }

    public function test_members_cannot_reject_payments(): void
    {
        $member = User::factory()->create();
        $proof = $this->proofFor($member);

        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListFeePayments::class)
            ->assertTableActionHidden('Reject Payment', $proof);
    }

    public function test_admins_can_reject_pending_payments(): void
    {
        $admin = User::factory()->admin()->create();
        $proof = $this->proofFor(User::factory()->create());

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListFeePayments::class)
            ->assertTableActionVisible('Reject Payment', $proof);
    }

    public function test_rejecting_a_payment_records_the_reason_and_grants_no_credit(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $bill = FeePeriod::create([
            'user_id' => $member->id,
            'month_date' => '2026-01-01',
            'amount_due' => 100,
            'status' => 'unpaid',
        ]);
        $proof = PaymentProof::create([
            'user_id' => $member->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => 100,
            'status' => 'pending',
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListFeePayments::class)
            ->callTableAction('Reject Payment', $proof, ['admin_note' => 'Receipt does not match'])
            ->assertHasNoTableActionErrors();

        $proof->refresh();
        $this->assertSame('rejected', $proof->status);
        $this->assertSame('Receipt does not match', $proof->admin_note);
        $this->assertSame(0, PaymentAllocation::count());
        $this->assertSame('unpaid', $bill->fresh()->status);
        $this->assertSame(0.0, $member->fresh()->credit);
    }

    public function test_rejecting_a_payment_requires_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $proof = $this->proofFor(User::factory()->create());

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListFeePayments::class)
            ->callTableAction('Reject Payment', $proof, ['admin_note' => ''])
            ->assertHasTableActionErrors(['admin_note']);

        $this->assertSame('pending', $proof->fresh()->status);
    }

    public function test_status_icon_handles_an_unexpected_status(): void
    {
        $admin = User::factory()->admin()->create();
        PaymentProof::create([
            'user_id' => User::factory()->create()->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => 10,
            'status' => 'archived', // not one of pending/verified/rejected
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListFeePayments::class)->assertSuccessful();
    }
}
