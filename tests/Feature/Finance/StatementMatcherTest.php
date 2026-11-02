<?php

namespace Tests\Feature\Finance;

use App\Enums\StatementLineOutcome;
use App\Exceptions\PaymentNotPendingException;
use App\Models\Payment\BankStatementLine;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentAllocation;
use App\Models\Payment\PaymentProof;
use App\Models\Payment\PaymentReceipt;
use App\Models\User;
use App\Notifications\PaymentVerified;
use App\Services\BankStatements\MatchResult;
use App\Services\BankStatements\StatementMatcher;
use App\Services\FinanceService;
use App\Support\ReferenceCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StatementMatcherTest extends TestCase
{
    use RefreshDatabase;

    private const TXN_DATE = '2026-10-05';

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        $this->travelTo(Carbon::parse('2026-10-10 10:00'));
        $this->member = User::factory()->create();
    }

    private function proof(User $user, float $amount, string $status = 'pending', array $extra = []): PaymentProof
    {
        $proof = PaymentProof::create([
            'user_id' => $user->id,
            'proof_image_path' => 'receipts/r.jpg',
            'amount_claimed' => $amount,
            'status' => $status,
        ] + $extra);

        // Uploaded the day before the transfer unless a test says otherwise.
        $proof->forceFill(['created_at' => $extra['created_at'] ?? Carbon::parse('2026-10-04 09:00')])->save();

        return $proof;
    }

    private function line(?User $user, string $amount, array $extra = []): BankStatementLine
    {
        return BankStatementLine::factory()->create($extra + [
            'txn_date' => self::TXN_DATE,
            'amount' => $amount,
            'reference_text' => $user ? 'FEES '.ReferenceCode::for($user->id).' TQ' : 'MONTHLY FEES',
        ]);
    }

    /** @param  list<BankStatementLine>  $lines */
    private function match(array $lines): MatchResult
    {
        return app(StatementMatcher::class)->match(collect($lines));
    }

    private function bill(User $user, string $monthDate, float $amount): FeePeriod
    {
        return FeePeriod::create([
            'user_id' => $user->id,
            'month_date' => $monthDate,
            'amount_due' => $amount,
            'status' => 'unpaid',
        ]);
    }

    public function test_unique_exact_match_is_verified_allocated_receipted_and_notified(): void
    {
        Notification::fake();
        $bill = $this->bill($this->member, '2026-10-01', 30);
        $proof = $this->proof($this->member, 30);
        $line = $this->line($this->member, '30.00', ['bank_ref_no' => 'CIMB123456']);

        $result = $this->match([$line]);

        $proof->refresh();
        $line->refresh();
        $this->assertSame('verified', $proof->status);
        $this->assertSame(3000, FinanceService::toCents($proof->amount_verified));
        $this->assertStringContainsString('CIMB123456', $proof->admin_note);
        $this->assertSame('Verified from bank statement (CIMB ref CIMB123456)', $proof->admin_note);
        $this->assertSame(StatementLineOutcome::AutoVerified, $line->outcome);
        $this->assertSame($proof->id, $line->payment_proof_id);
        $this->assertSame($this->member->id, $line->user_id);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertTrue(PaymentReceipt::where('payment_proof_id', $proof->id)->exists());
        $this->assertSame(1, $result->autoVerified);
        $this->assertSame([$proof->id], $result->verifiedProofIds);
        Notification::assertSentTo($this->member, PaymentVerified::class);
    }

    public function test_multi_month_transfer_spreads_over_bills(): void
    {
        Notification::fake();
        $bills = collect(['2026-08-01', '2026-09-01', '2026-10-01'])
            ->map(fn (string $month) => $this->bill($this->member, $month, 35));
        $this->proof($this->member, 105);

        $this->match([$this->line($this->member, '105.00')]);

        $bills->each(fn (FeePeriod $bill) => $this->assertSame('paid', $bill->fresh()->status));
    }

    public function test_no_code_and_invalid_code_are_left_alone(): void
    {
        Notification::fake();
        $proof = $this->proof($this->member, 30);
        $valid = ReferenceCode::for($this->member->id);
        $wrongCheckDigit = substr($valid, 0, -1).((((int) substr($valid, -1)) + 1) % 10);
        $noCode = $this->line(null, '30.00');
        $invalid = $this->line(null, '30.00', ['reference_text' => "FEES {$wrongCheckDigit}"]);

        $result = $this->match([$noCode, $invalid]);

        $this->assertSame(StatementLineOutcome::NoCode, $noCode->fresh()->outcome);
        $this->assertSame(StatementLineOutcome::NoCode, $invalid->fresh()->outcome);
        $this->assertNull($invalid->fresh()->user_id);
        $this->assertSame('pending', $proof->fresh()->status);
        $this->assertSame(2, $result->unmatched);
        Notification::assertNothingSent();
    }

    public function test_amount_mismatch_needs_review(): void
    {
        Notification::fake();
        $proof = $this->proof($this->member, 105);
        $line = $this->line($this->member, '100.00');

        $this->match([$line]);

        $this->assertSame(StatementLineOutcome::NeedsReview, $line->fresh()->outcome);
        $this->assertSame($this->member->id, $line->fresh()->user_id);
        $this->assertNull($line->fresh()->payment_proof_id);
        $this->assertSame('pending', $proof->fresh()->status);
    }

    public function test_two_pending_proofs_of_the_same_amount_need_review(): void
    {
        Notification::fake();
        $first = $this->proof($this->member, 30);
        $second = $this->proof($this->member, 30);
        $line = $this->line($this->member, '30.00');

        $this->match([$line]);

        $this->assertSame(StatementLineOutcome::NeedsReview, $line->fresh()->outcome);
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_two_lines_competing_for_one_proof_both_need_review(): void
    {
        Notification::fake();
        $proof = $this->proof($this->member, 30);
        $first = $this->line($this->member, '30.00');
        $second = $this->line($this->member, '30.00');

        $result = $this->match([$first, $second]);

        $this->assertSame(StatementLineOutcome::NeedsReview, $first->fresh()->outcome);
        $this->assertSame(StatementLineOutcome::NeedsReview, $second->fresh()->outcome);
        $this->assertSame('pending', $proof->fresh()->status);
        $this->assertSame(0, $result->autoVerified);
        $this->assertSame(2, $result->unmatched);
    }

    public function test_proof_older_than_the_lookback_is_not_a_candidate(): void
    {
        Notification::fake();
        $stale = $this->proof($this->member, 30, extra: ['created_at' => Carbon::parse('2026-09-27 12:00')]);
        $line = $this->line($this->member, '30.00');

        $this->match([$line]);

        $this->assertSame(StatementLineOutcome::NeedsReview, $line->fresh()->outcome);
        $this->assertSame('pending', $stale->fresh()->status);

        // Exactly seven days before the transfer, at the start of that day, is still in.
        $other = User::factory()->create();
        $inWindow = $this->proof($other, 30, extra: ['created_at' => Carbon::parse('2026-09-28 00:00')]);
        $line = $this->line($other, '30.00');

        $this->match([$line]);

        $this->assertSame(StatementLineOutcome::AutoVerified, $line->fresh()->outcome);
        $this->assertSame('verified', $inWindow->fresh()->status);
    }

    public function test_already_verified_proof_is_confirmed_not_reverified(): void
    {
        Notification::fake();
        $this->bill($this->member, '2026-10-01', 30);
        $proof = $this->proof($this->member, 30);
        app(FinanceService::class)->verifyAndAllocate($proof, 30);
        $proof->refresh();
        $allocations = PaymentAllocation::count();
        $line = $this->line($this->member, '30.00');

        $result = $this->match([$line]);

        $line->refresh();
        $this->assertSame(StatementLineOutcome::Confirmed, $line->outcome);
        $this->assertSame($proof->id, $line->payment_proof_id);
        $this->assertSame($allocations, PaymentAllocation::count());
        $this->assertSame(1, PaymentReceipt::count());
        $this->assertEquals($proof->verified_at, $proof->fresh()->verified_at);
        $this->assertSame(1, $result->confirmed);
        $this->assertSame([], $result->verifiedProofIds);
        Notification::assertNothingSent();
    }

    public function test_verified_proof_outside_the_confirm_window_is_not_confirmed(): void
    {
        Notification::fake();
        $this->proof($this->member, 30, 'verified', [
            'amount_verified' => 30,
            'verified_at' => Carbon::parse('2026-11-05 00:00'), // txn + 31 days
        ]);
        $early = User::factory()->create();
        $this->proof($early, 30, 'verified', [
            'amount_verified' => 30,
            'verified_at' => Carbon::parse('2026-10-01 23:59'), // four days before the transfer
        ]);
        $lateLine = $this->line($this->member, '30.00');
        $earlyLine = $this->line($early, '30.00');

        $this->match([$lateLine, $earlyLine]);

        $this->assertSame(StatementLineOutcome::NoProof, $lateLine->fresh()->outcome);
        $this->assertSame(StatementLineOutcome::NoProof, $earlyLine->fresh()->outcome);
        $this->assertNull($lateLine->fresh()->payment_proof_id);
    }

    public function test_old_line_does_not_verify_a_newer_proof_after_its_own_was_verified_by_hand(): void
    {
        Notification::fake();
        // 15 Sep: RM15 with the code. 16 Sep: treasurer verifies that proof by hand.
        $september = $this->proof($this->member, 15, extra: ['created_at' => Carbon::parse('2026-09-15 08:00')]);
        $this->travelTo(Carbon::parse('2026-09-16 10:00'));
        app(FinanceService::class)->verifyAndAllocate($september, 15);
        // 1 Oct: October's RM15 proof, still pending. 3 Oct: the September statement is imported.
        $october = $this->proof($this->member, 15, extra: ['created_at' => Carbon::parse('2026-10-01 09:00')]);
        $this->travelTo(Carbon::parse('2026-10-03 10:00'));
        $line = $this->line($this->member, '15.00', ['txn_date' => '2026-09-15']);

        $result = $this->match([$line]);

        $this->assertSame('pending', $october->fresh()->status);
        $this->assertNotSame(StatementLineOutcome::AutoVerified, $line->fresh()->outcome);
        $this->assertNotSame($october->id, $line->fresh()->payment_proof_id);
        $this->assertSame([], $result->verifiedProofIds);
        Notification::assertNothingSent();
    }

    public function test_line_with_both_a_pending_candidate_and_a_confirmable_proof_needs_review(): void
    {
        Notification::fake();
        $verified = $this->proof($this->member, 30, 'verified', [
            'amount_verified' => 30,
            'verified_at' => Carbon::parse('2026-10-06 10:00'),
        ]);
        $pending = $this->proof($this->member, 30, extra: ['created_at' => Carbon::parse('2026-10-08 09:00')]);
        $line = $this->line($this->member, '30.00');

        $result = $this->match([$line]);

        $line->refresh();
        $this->assertSame(StatementLineOutcome::NeedsReview, $line->outcome);
        $this->assertNull($line->payment_proof_id);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertNull($verified->fresh()->bankStatementLine);
        $this->assertSame(0, $result->autoVerified);
        $this->assertSame(0, $result->confirmed);
        Notification::assertNothingSent();
    }

    public function test_proof_created_more_than_the_lookahead_after_the_transfer_is_not_a_candidate(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-25 10:00'));
        $late = $this->proof($this->member, 30, extra: ['created_at' => Carbon::parse('2026-10-20 10:00')]); // txn + 15 days
        $line = $this->line($this->member, '30.00');

        $this->match([$line]);

        $this->assertSame(StatementLineOutcome::NeedsReview, $line->fresh()->outcome);
        $this->assertNull($line->fresh()->payment_proof_id);
        $this->assertSame('pending', $late->fresh()->status);
    }

    public function test_lookahead_boundary_is_the_end_of_the_fourteenth_day(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-25 10:00'));
        $inside = $this->proof($this->member, 30, extra: ['created_at' => Carbon::parse('2026-10-19 23:59:59')]);
        $insideLine = $this->line($this->member, '30.00');
        $other = User::factory()->create();
        $outside = $this->proof($other, 30, extra: ['created_at' => Carbon::parse('2026-10-20 00:00:00')]);
        $outsideLine = $this->line($other, '30.00');

        $this->match([$insideLine, $outsideLine]);

        $this->assertSame(StatementLineOutcome::AutoVerified, $insideLine->fresh()->outcome);
        $this->assertSame('verified', $inside->fresh()->status);
        $this->assertSame(StatementLineOutcome::NeedsReview, $outsideLine->fresh()->outcome);
        $this->assertSame('pending', $outside->fresh()->status);
    }

    public function test_proof_verified_within_the_confirm_slack_before_the_transfer_is_confirmed(): void
    {
        Notification::fake();
        $proof = $this->proof($this->member, 30, 'verified', [
            'amount_verified' => 30,
            'verified_at' => Carbon::parse('2026-10-02 00:00'), // txn - 3 days, start of day
        ]);
        $line = $this->line($this->member, '30.00');

        $result = $this->match([$line]);

        $this->assertSame(StatementLineOutcome::Confirmed, $line->fresh()->outcome);
        $this->assertSame($proof->id, $line->fresh()->payment_proof_id);
        $this->assertSame(1, $result->confirmed);
    }

    public function test_code_without_any_proof_is_no_proof(): void
    {
        Notification::fake();
        $line = $this->line($this->member, '30.00');

        $result = $this->match([$line]);

        $this->assertSame(StatementLineOutcome::NoProof, $line->fresh()->outcome);
        $this->assertSame($this->member->id, $line->fresh()->user_id);
        $this->assertSame(1, $result->unmatched);
    }

    public function test_code_of_a_deleted_user_is_no_code(): void
    {
        Notification::fake();
        $gone = User::factory()->create();
        $line = $this->line($gone, '30.00');
        $gone->delete();

        $this->match([$line]);

        $this->assertSame(StatementLineOutcome::NoCode, $line->fresh()->outcome);
        $this->assertNull($line->fresh()->user_id);
    }

    public function test_proof_verified_by_hand_mid_match_falls_back_to_review(): void
    {
        Notification::fake();
        $proof = $this->proof($this->member, 30);
        $line = $this->line($this->member, '30.00');
        $this->partialMock(FinanceService::class, function ($mock) {
            $mock->shouldReceive('verifyAndAllocate')->once()
                ->andThrow(new PaymentNotPendingException('Reviewed by hand meanwhile.'));
        });

        $result = $this->match([$line]);

        $this->assertSame(StatementLineOutcome::NeedsReview, $line->fresh()->outcome);
        $this->assertNull($line->fresh()->payment_proof_id);
        $this->assertNull($proof->fresh()->admin_note);
        $this->assertSame(0, $result->autoVerified);
        $this->assertSame([], $result->verifiedProofIds);
        Notification::assertNothingSent();
    }

    /** Makes verifyAndAllocate() throw for one proof and run for real for every other. */
    private function failVerificationOf(PaymentProof $failing, \Throwable $error): void
    {
        $this->partialMock(FinanceService::class, function ($mock) use ($failing, $error) {
            $mock->shouldReceive('verifyAndAllocate')
                ->with(Mockery::on(fn (PaymentProof $proof) => $proof->id === $failing->id), Mockery::any())
                ->andThrow($error);
            $mock->shouldReceive('verifyAndAllocate')
                ->with(Mockery::on(fn (PaymentProof $proof) => $proof->id !== $failing->id), Mockery::any())
                ->passthru();
        });
    }

    public function test_a_line_that_throws_is_reported_left_for_review_and_the_rest_still_match(): void
    {
        Notification::fake();
        Exceptions::fake();
        $other = User::factory()->create();
        $broken = $this->proof($this->member, 30);
        $fine = $this->proof($other, 30);
        $brokenLine = $this->line($this->member, '30.00');
        $fineLine = $this->line($other, '30.00');
        $this->failVerificationOf($broken, new RuntimeException('allocation blew up'));

        $result = $this->match([$brokenLine, $fineLine]);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getPrevious()?->getMessage() === 'allocation blew up'
            && str_contains($e->getMessage(), "line {$brokenLine->id}"));
        $this->assertSame(StatementLineOutcome::NeedsReview, $brokenLine->fresh()->outcome);
        $this->assertNull($brokenLine->fresh()->payment_proof_id);
        $this->assertSame('pending', $broken->fresh()->status);
        $this->assertSame(StatementLineOutcome::AutoVerified, $fineLine->fresh()->outcome);
        $this->assertSame('verified', $fine->fresh()->status);
        $this->assertSame(1, $result->autoVerified);
        $this->assertSame(1, $result->unmatched);
        Notification::assertSentTo($other, PaymentVerified::class);
        Notification::assertNotSentTo($this->member, PaymentVerified::class);
    }

    public function test_members_verified_before_a_failing_line_are_still_notified(): void
    {
        Notification::fake();
        Exceptions::fake();
        $other = User::factory()->create();
        $fine = $this->proof($other, 30);
        $broken = $this->proof($this->member, 30);
        $fineLine = $this->line($other, '30.00');
        $brokenLine = $this->line($this->member, '30.00');
        $this->failVerificationOf($broken, new RuntimeException('allocation blew up'));

        $this->match([$fineLine, $brokenLine]);

        $this->assertSame(StatementLineOutcome::NeedsReview, $brokenLine->fresh()->outcome);
        Notification::assertSentTo($other, PaymentVerified::class);
    }

    public function test_a_genuine_model_not_found_is_reported_not_swallowed(): void
    {
        Notification::fake();
        Exceptions::fake();
        $proof = $this->proof($this->member, 30);
        $line = $this->line($this->member, '30.00');
        $this->failVerificationOf($proof, (new ModelNotFoundException)->setModel(FeePeriod::class));

        $this->match([$line]);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getPrevious() instanceof ModelNotFoundException);
        $this->assertSame(StatementLineOutcome::NeedsReview, $line->fresh()->outcome);
        $this->assertSame('pending', $proof->fresh()->status);
    }

    public function test_one_failing_notification_does_not_stop_the_others(): void
    {
        $other = User::factory()->create();
        $this->proof($this->member, 30);
        $this->proof($other, 30);
        $lines = [$this->line($this->member, '30.00'), $this->line($other, '30.00')];
        $failFor = $this->member->id;
        Event::listen(NotificationSending::class, function (NotificationSending $event) use ($failFor) {
            if ($event->notifiable->id === $failFor) {
                throw new RuntimeException('channel down');
            }
        });

        $result = $this->match($lines);

        $this->assertSame(2, $result->autoVerified);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->member->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $other->id, 'type' => PaymentVerified::class]);
    }
}
