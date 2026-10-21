<?php

namespace Tests\Feature\Email;

use App\Models\EmailBudget;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\QueuedEmail;
use App\Models\User;
use App\Notifications\PaymentVerified;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/** The receipt PDF must survive the raw-MIME spool used when the daily email budget is spent. */
class ReceiptAttachmentSpoolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $transport = new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'noop://';
            }
        };

        Mail::extend('noop', fn () => $transport);
        config([
            'mail.mailers.noop' => ['transport' => 'noop'],
            'mail.mailers.budgeted.inner' => 'noop',
            'mail.default' => 'budgeted',
        ]);
    }

    public function test_the_receipt_pdf_survives_the_spool(): void
    {
        $member = User::factory()->create(['activated_at' => now()]);
        FeePeriod::create(['user_id' => $member->id, 'month_date' => '2026-10-01', 'amount_due' => 30, 'status' => 'unpaid']);
        $proof = PaymentProof::create(['user_id' => $member->id, 'proof_image_path' => 'r.jpg', 'amount_claimed' => 30, 'status' => 'pending']);
        app(FinanceService::class)->verifyAndAllocate($proof, 30);
        $proof = $proof->fresh();

        for ($i = 0; $i < EmailBudget::DAILY_LIMIT; $i++) {
            EmailBudget::claim();
        }

        $member->notify(new PaymentVerified($proof));

        $queued = QueuedEmail::firstOrFail();
        $this->assertStringContainsString('application/pdf', $queued->mime);
        $this->assertStringContainsString($proof->receipt->filename(), $queued->mime);

        // Decode the attachment body from the raw MIME and check it is a real PDF.
        $this->assertSame(1, preg_match(
            '/Content-Type: application\/pdf[^\r\n]*\r?\n(?:[^\r\n]+\r?\n)*?\r?\n([A-Za-z0-9+\/=\r\n]+)/',
            $queued->mime,
            $m,
        ));
        $this->assertStringStartsWith('%PDF', base64_decode(preg_replace('/\s+/', '', $m[1])));
    }
}
