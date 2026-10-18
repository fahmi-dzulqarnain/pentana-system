<?php

namespace Tests\Feature;

use App\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Notifications\PaymentVerified;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Traits\Localizable;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentVerifiedNotificationTest extends TestCase
{
    use Localizable;
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create(['activated_at' => now()]);
        $this->actingAs(User::factory()->treasurer()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    private function bill(string $month, float $due = 30): FeePeriod
    {
        return FeePeriod::create(['user_id' => $this->member->id, 'month_date' => $month, 'amount_due' => $due, 'status' => 'unpaid']);
    }

    private function proof(float $claimed): PaymentProof
    {
        return PaymentProof::create(['user_id' => $this->member->id, 'proof_image_path' => 'r.jpg', 'amount_claimed' => $claimed, 'status' => 'pending']);
    }

    /** Verify through the real admin action so the allocation the email describes really happened. */
    private function verify(PaymentProof $proof, float $actual): void
    {
        Livewire::test(ViewFeePayment::class, ['record' => $proof->getKey()])
            ->callAction('verifyPayment', data: ['actual_amount' => $actual])
            ->assertNotified('Payment verified');
    }

    private function sentMail(): array
    {
        $sent = null;
        Notification::assertSentTo($this->member, PaymentVerified::class, function (PaymentVerified $n) use (&$sent) {
            $sent = $n->toMail($this->member);

            return true;
        });

        return [$sent->subject, implode("\n", $sent->introLines), $sent];
    }

    public function test_it_names_the_months_paid_and_a_clear_balance(): void
    {
        Notification::fake();
        $this->bill('2026-09-01');
        $this->bill('2026-10-01');

        $this->verify($this->proof(60), 60);

        [$subject, $text] = $this->sentMail();
        $this->assertSame('Your payment has been verified', $subject);
        $this->assertStringContainsString('We verified your payment of MYR 60.00.', $text);
        $this->assertStringContainsString('It paid for: September 2026, October 2026.', $text);
        $this->assertStringContainsString('You have no outstanding dues.', $text);
        $this->assertStringNotContainsString('you entered', strtolower($text));
    }

    public function test_it_explains_a_different_amount_and_what_is_still_owed(): void
    {
        Notification::fake();
        $this->bill('2026-09-01');
        $this->bill('2026-10-01');

        $this->verify($this->proof(60), 30);

        [, $text] = $this->sentMail();
        $this->assertStringContainsString('You entered MYR 60.00; MYR 30.00 is what reached the account.', $text);
        $this->assertStringContainsString('It paid for: September 2026.', $text);
        $this->assertStringContainsString('MYR 30.00 is still outstanding.', $text);
    }

    public function test_an_overpayment_is_reported_as_credit(): void
    {
        Notification::fake();
        $this->bill('2026-10-01');

        $this->verify($this->proof(50), 50);

        [, $text] = $this->sentMail();
        $this->assertStringContainsString('You have MYR 20.00 in credit, which will go towards your next bills.', $text);
    }

    public function test_with_no_bills_the_bell_says_it_went_to_credit(): void
    {
        $proof = $this->proof(30);
        $proof->update(['status' => 'verified', 'amount_verified' => 30]);

        $bell = (new PaymentVerified($proof->fresh()))->toDatabase($this->member);

        $this->assertSame('MYR 30.00, added to your credit.', $bell['body']);
    }

    public function test_months_follow_the_members_language(): void
    {
        $this->bill('2026-10-01');
        $proof = $this->proof(30);
        $this->verify($proof, 30);

        $bell = $this->withLocale('id', fn () => (new PaymentVerified($proof->fresh()))->toDatabase($this->member));

        $this->assertSame('MYR 30.00, untuk Oktober 2026.', $bell['body']);
    }
}
