<?php

namespace Tests\Feature;

use App\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Notifications\PaymentRejected;
use App\Notifications\PaymentVerified;
use Filament\Facades\Filament;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Traits\Localizable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PaymentRejectedNotificationTest extends TestCase
{
    use Localizable;
    use RefreshDatabase;

    private User $member;

    private PaymentProof $proof;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00'); // the email quotes the submission date
        $this->member = User::factory()->create(['activated_at' => now()]);
        $this->proof = PaymentProof::create([
            'user_id' => $this->member->id,
            'proof_image_path' => 'receipts/r.jpg',
            'amount_claimed' => 30,
            'status' => 'pending',
            'created_at' => '2026-10-16 09:08:00',
        ]);
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    private function reject(string $reason = 'Receipt is unreadable'): Testable
    {
        return Livewire::test(ViewFeePayment::class, ['record' => $this->proof->getKey()])
            ->callAction('rejectPayment', data: ['admin_note' => $reason]);
    }

    public function test_rejecting_notifies_the_member_by_email_and_bell(): void
    {
        Notification::fake();

        $this->reject()->assertNotified('Payment rejected');

        Notification::assertSentTo($this->member, PaymentRejected::class, function ($notification, array $channels) {
            return in_array('mail', $channels, true) && in_array('database', $channels, true);
        });
        Notification::assertCount(1);
    }

    public function test_verifying_sends_the_verified_notice_not_a_rejection(): void
    {
        Notification::fake();

        Livewire::test(ViewFeePayment::class, ['record' => $this->proof->getKey()])
            ->callAction('verifyPayment', data: ['actual_amount' => 30]);

        Notification::assertNotSentTo($this->member, PaymentRejected::class);
        Notification::assertSentTo($this->member, PaymentVerified::class);
    }

    public function test_the_email_carries_the_amount_date_reason_and_a_link_to_the_payment(): void
    {
        $this->proof->update(['status' => 'rejected', 'admin_note' => 'Amount does not match the receipt']);

        $mail = (new PaymentRejected($this->proof->fresh()))->toMail($this->member);
        $text = implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);

        $this->assertSame('Your payment could not be verified', $mail->subject);
        $this->assertStringContainsString('MYR 30.00', $text);
        $this->assertStringContainsString('16 Oct 2026', $text);
        $this->assertStringContainsString('Reason: Amount does not match the receipt', $text);
        $this->assertStringEndsWith('/account/fee-payments/'.$this->proof->id, $mail->actionUrl);
    }

    public function test_the_member_reads_it_in_their_language_with_the_reason_as_typed(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $this->member->forceFill(['locale' => 'ms'])->save();
        $this->proof->update(['status' => 'rejected', 'admin_note' => 'Resit kabur, sila hantar semula']);

        // Laravel renders each notification in the recipient's preferredLocale(); the fake skips
        // that step, so render in that locale here.
        $this->assertSame('ms', $this->member->preferredLocale());
        $mail = $this->withLocale('ms', fn () => (new PaymentRejected($this->proof->fresh()))->toMail($this->member));

        $this->assertSame('Bayaran anda tidak dapat disahkan', $mail->subject);
        $this->assertContains('Sebab: Resit kabur, sila hantar semula', $mail->introLines);
    }

    public function test_markup_in_the_reason_is_shown_as_text_in_the_bell(): void
    {
        $this->proof->update(['status' => 'rejected', 'admin_note' => '<b>Wrong</b> amount']);

        $bell = (new PaymentRejected($this->proof->fresh()))->toDatabase($this->member);

        $this->assertStringContainsString('&lt;b&gt;Wrong&lt;/b&gt; amount', $bell['body']);
    }

    public function test_a_delivery_failure_keeps_the_rejection_and_warns_the_admin(): void
    {
        $this->mock(Dispatcher::class)->shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));

        // assertNotified consumes the flashed notifications, so only the warning is checked here.
        $this->reject()->assertNotified('The member could not be notified.');

        $this->assertSame('rejected', $this->proof->fresh()->status);
    }
}
