<?php

namespace Tests\Feature;

use App\Filament\Resources\FeePeriods\Pages\EditFeePeriod;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Services\FinanceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Changing a bill's amount after payments landed on it must re-derive its status
 * and move any money the bill no longer needs to the member's other bills.
 */
class FeeAmountChangeTest extends TestCase
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

    private function pay(User $user, float $amount): void
    {
        $proof = PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/test.jpg',
            'amount_claimed' => $amount,
            'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, $amount);
    }

    public function test_lowering_amount_to_what_was_paid_marks_bill_paid(): void
    {
        $user = User::factory()->create();
        $august = $this->bill($user, '2026-08-01', 25);
        $this->pay($user, 15);
        $this->assertSame('partial', $august->fresh()->status);

        $august->fresh()->update(['amount_due' => 15]);

        $this->assertSame('paid', $august->fresh()->status);
    }

    public function test_lowering_a_paid_bill_moves_the_excess_to_the_next_bill(): void
    {
        $user = User::factory()->create();
        $august = $this->bill($user, '2026-08-01', 25);
        $september = $this->bill($user, '2026-09-01', 15);
        $this->pay($user, 25);

        $august->fresh()->update(['amount_due' => 15]);

        $this->assertSame('paid', $august->fresh()->status);
        $this->assertEquals(15, $august->allocations()->sum('amount_allocated'));
        $this->assertSame('partial', $september->fresh()->status);
        $this->assertEquals(10, $september->allocations()->sum('amount_allocated'));
    }

    public function test_excess_with_no_other_bill_becomes_credit(): void
    {
        $user = User::factory()->create();
        $august = $this->bill($user, '2026-08-01', 25);
        $this->pay($user, 25);

        $august->fresh()->update(['amount_due' => 15]);

        $this->assertSame('paid', $august->fresh()->status);
        $this->assertSame(10.0, $user->fresh()->credit);
    }

    public function test_raising_amount_reopens_bill_and_draws_on_credit(): void
    {
        $user = User::factory()->create();
        $august = $this->bill($user, '2026-08-01', 15);
        $this->pay($user, 20); // 15 to August, 5 credit

        $august->fresh()->update(['amount_due' => 25]);

        $this->assertSame('partial', $august->fresh()->status);
        $this->assertEquals(20, $august->allocations()->sum('amount_allocated'));
        $this->assertSame(0.0, $user->fresh()->credit);
    }

    public function test_reconcile_command_repairs_bills_edited_before_the_fix(): void
    {
        $user = User::factory()->create();
        $august = $this->bill($user, '2026-08-01', 25);
        $september = $this->bill($user, '2026-09-01', 15);
        $this->pay($user, 15);
        // Simulate the old behaviour: amount changed with no reconcile.
        FeePeriod::withoutEvents(fn () => $august->fresh()->update(['amount_due' => 15]));
        $this->assertSame('partial', $august->fresh()->status);

        $this->artisan('app:reconcile-fee-statuses', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('partial', $august->fresh()->status);

        $this->artisan('app:reconcile-fee-statuses')
            ->expectsOutputToContain('Reconciled 1 bill(s).')
            ->assertSuccessful();
        $this->assertSame('paid', $august->fresh()->status);
        $this->assertSame('unpaid', $september->fresh()->status);
    }

    public function test_reconcile_command_flags_only_bills_whose_status_disagrees_with_what_was_paid(): void
    {
        $neverPaid = $this->bill(User::factory()->create(), '2026-08-01', 25);
        FeePeriod::withoutEvents(fn () => $neverPaid->update(['status' => 'paid']));

        $partPayer = User::factory()->create();
        $partPaid = $this->bill($partPayer, '2026-08-01', 25);
        $this->pay($partPayer, 10);
        FeePeriod::withoutEvents(fn () => $partPaid->fresh()->update(['status' => 'unpaid']));

        // Left alone: already consistent (unpaid, part-paid, paid in full), or nothing is due at all.
        $untouched = $this->bill(User::factory()->create(), '2026-08-01', 25);
        $partPayerTwo = User::factory()->create();
        $stillPartial = $this->bill($partPayerTwo, '2026-08-01', 25);
        $this->pay($partPayerTwo, 10);
        $fullPayer = User::factory()->create();
        $settled = $this->bill($fullPayer, '2026-08-01', 25);
        $this->pay($fullPayer, 25);
        $free = $this->bill(User::factory()->create(), '2026-08-01', 0);
        FeePeriod::withoutEvents(fn () => $free->fresh()->update(['status' => 'unpaid']));

        $this->artisan('app:reconcile-fee-statuses', ['--dry-run' => true])
            ->expectsOutputToContain('Would reconcile 2 bill(s).')
            ->assertSuccessful();
        $this->assertSame('paid', $neverPaid->fresh()->status);
        $this->assertSame('unpaid', $partPaid->fresh()->status);

        $this->artisan('app:reconcile-fee-statuses')
            ->expectsOutputToContain('Reconciled 2 bill(s).')
            ->assertSuccessful();

        $this->assertSame('unpaid', $neverPaid->fresh()->status);
        $this->assertSame('partial', $partPaid->fresh()->status);
        $this->assertSame('unpaid', $untouched->fresh()->status);
        $this->assertSame('partial', $stillPartial->fresh()->status);
        $this->assertSame('paid', $settled->fresh()->status);
        $this->assertSame('unpaid', $free->fresh()->status);
    }

    public function test_editing_the_amount_in_the_admin_form_updates_status(): void
    {
        $this->actingAs(User::factory()->treasurer()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        $member = User::factory()->create();
        $august = $this->bill($member, '2026-08-01', 25);
        $this->pay($member, 15);

        Livewire::test(EditFeePeriod::class, ['record' => $august->getRouteKey()])
            ->fillForm(['amount_due' => 15])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('paid', $august->fresh()->status);
    }
}
