<?php

namespace Tests\Feature\Receipts;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\Payment\PaymentReceipt;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\ReceiptService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class IssueReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create(['name' => 'Banmathi']);
    }

    private function bill(string $monthDate, float $amount, ?User $user = null): FeePeriod
    {
        return FeePeriod::create([
            'user_id' => ($user ?? $this->member)->id,
            'month_date' => $monthDate,
            'amount_due' => $amount,
            'status' => 'unpaid',
        ]);
    }

    private function proof(float $claimed, ?User $user = null, array $extra = []): PaymentProof
    {
        return PaymentProof::create($extra + [
            'user_id' => ($user ?? $this->member)->id,
            'proof_image_path' => 'receipts/r.jpg',
            'amount_claimed' => $claimed,
            'status' => 'pending',
        ]);
    }

    private function verify(PaymentProof $proof, float $amount): PaymentReceipt
    {
        app(FinanceService::class)->verifyAndAllocate($proof, $amount);

        return $proof->fresh()->receipt;
    }

    private function assertInvariant(PaymentReceipt $receipt): void
    {
        $cents = fn ($amount) => (int) round(((float) $amount) * 100);
        $lines = array_sum(array_map(fn ($line) => $cents($line['amount']), $receipt->lines));

        $this->assertSame(
            $cents($receipt->amount_received),
            $lines - $cents($receipt->credit_used) + $cents($receipt->credit_added),
        );
    }

    private function lastSequence(int $year): int
    {
        return (int) DB::table('receipt_counters')->where('year', $year)->value('last_sequence');
    }

    public function test_verifying_issues_the_first_number_of_the_year(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 10:00'));
        $this->bill('2026-10-01', 30);

        $receipt = $this->verify($this->proof(30), 30);

        $this->assertSame('PEN-2026-0001', $receipt->number);
        $this->assertSame(2026, $receipt->year);
        $this->assertSame(1, $receipt->sequence);
        $this->assertSame($this->member->id, $receipt->user_id);
        $this->assertSame('Banmathi', $receipt->member_name);
        $this->assertSame($this->member->email, $receipt->member_email);
        $this->assertTrue($receipt->verified_at->equalTo(now()));

        $other = User::factory()->create();
        $this->assertSame('PEN-2026-0002', $this->verify($this->proof(30, $other), 30)->number);
    }

    public function test_sequence_restarts_each_year(): void
    {
        // 23:00 MYT on 31 Dec.
        $this->travelTo(Carbon::parse('2026-12-31 15:00', 'UTC'));
        $this->assertSame('PEN-2026-0001', $this->verify($this->proof(30), 30)->number);

        // 00:30 MYT on 1 Jan: a new year in the app timezone, though still 2026 in UTC.
        $this->travelTo(Carbon::parse('2026-12-31 16:30', 'UTC'));
        $receipt = $this->verify($this->proof(30), 30);

        $this->assertSame('PEN-2027-0001', $receipt->number);
        $this->assertSame(2027, $receipt->year);
    }

    public function test_failed_verification_issues_no_number(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 10:00'));
        $proof = $this->proof(30);

        // Allocation fails before a number is taken.
        $this->partialMock(FinanceService::class, function ($mock) {
            $mock->shouldReceive('applyAvailableFunds')->andThrow(new RuntimeException('allocation failed'));
        });

        try {
            app(FinanceService::class)->verifyAndAllocate($proof, 30);
            $this->fail('Expected the verification to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('allocation failed', $e->getMessage());
        }

        $this->assertSame(0, PaymentReceipt::count());
        $this->assertSame(0, $this->lastSequence(2026));
        $this->assertSame('pending', $proof->fresh()->status);

        // The snapshot fails after the number is taken.
        app()->forgetInstance(FinanceService::class);
        $this->bill('2026-10-01', 30);
        $this->partialMock(ReceiptService::class, function ($mock) {
            $mock->shouldReceive('nextNumber')->once()->passthru();
            $mock->shouldReceive('lineLabel')->andThrow(new RuntimeException('snapshot failed'));
        });

        try {
            app(FinanceService::class)->verifyAndAllocate($proof->fresh(), 30);
            $this->fail('Expected the verification to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('snapshot failed', $e->getMessage());
        }

        $this->assertSame(0, PaymentReceipt::count());
        $this->assertSame(0, $this->lastSequence(2026));
        $this->assertSame('pending', $proof->fresh()->status);

        app()->forgetInstance(ReceiptService::class);
        $this->assertSame('PEN-2026-0001', $this->verify($proof->fresh(), 30)->number);
    }

    public function test_old_credit_case_lists_what_the_approval_settled(): void
    {
        $this->proof(20, extra: ['status' => 'verified', 'amount_verified' => 20, 'verified_at' => now()]);
        $this->bill('2026-09-01', 30);

        $receipt = $this->verify($this->proof(30), 30);

        $this->assertEquals(
            [['label' => 'Monthly fee: September 2026', 'month' => '2026-09-01', 'amount' => '30.00']],
            $receipt->lines,
        );
        $this->assertSame('20.00', $receipt->credit_used);
        $this->assertSame('20.00', $receipt->credit_added);
        $this->assertSame('30.00', $receipt->amount_received);
        $this->assertInvariant($receipt);
    }

    public function test_credit_only_payment(): void
    {
        $receipt = $this->verify($this->proof(50), 50);

        $this->assertSame([], $receipt->lines);
        $this->assertSame('50.00', $receipt->credit_added);
        $this->assertSame('0.00', $receipt->credit_used);
        $this->assertSame('50.00', $receipt->amount_received);
    }

    public function test_cents_keep_the_invariant(): void
    {
        $this->bill('2026-10-01', 30);
        $first = $this->verify($this->proof(25.50), 25.50);

        $this->bill('2026-11-01', 30);
        $second = $this->verify($this->proof(40.25), 40.25);

        $this->assertInvariant($first);
        $this->assertInvariant($second);
        $this->assertSame([
            ['label' => 'Monthly fee: October 2026', 'month' => '2026-10-01', 'amount' => '4.50'],
            ['label' => 'Monthly fee: November 2026', 'month' => '2026-11-01', 'amount' => '30.00'],
        ], $second->lines);
        $this->assertSame('0.00', $second->credit_used);
        $this->assertSame('5.75', $second->credit_added);
    }

    public function test_snapshot_does_not_change_when_allocations_shift_later(): void
    {
        $this->bill('2026-09-01', 30);
        $a = $this->verify($this->proof(50), 50);
        $before = $a->only(['lines', 'credit_used', 'credit_added', 'amount_received']);

        // B's approval spends A's leftover credit on the new bill.
        $this->bill('2026-10-01', 30);
        $b = $this->verify($this->proof(10), 10);

        $this->assertEquals($before, $a->fresh()->only(['lines', 'credit_used', 'credit_added', 'amount_received']));
        $this->assertSame('20.00', $b->credit_used);
        $this->assertInvariant($b);
    }

    public function test_verifying_twice_is_refused(): void
    {
        $proof = $this->proof(30);
        $this->verify($proof, 30);

        try {
            app(FinanceService::class)->verifyAndAllocate($proof->fresh(), 30);
            $this->fail('Expected the second verification to throw.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, PaymentReceipt::count());
    }

    public function test_line_labels(): void
    {
        $service = app(ReceiptService::class);
        $period = fn (array $attributes) => new FeePeriod(['month_date' => '2026-09-01', 'amount_due' => 30] + $attributes);

        $this->assertSame('Monthly fee: September 2026', $service->lineLabel($period(['type' => 'regular'])));
        $this->assertSame('Adjustment: September 2026', $service->lineLabel($period(['type' => 'adjustment'])));
        $this->assertSame(
            'Adjustment: September 2026 — Back-dated rate change',
            $service->lineLabel($period(['type' => 'adjustment', 'remarks' => 'Back-dated rate change'])),
        );
        $this->assertSame('Special Levy: September 2026', $service->lineLabel($period(['type' => 'special_levy'])));
    }
}
