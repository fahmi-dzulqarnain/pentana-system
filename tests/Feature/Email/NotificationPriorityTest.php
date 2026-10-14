<?php

namespace Tests\Feature\Email;

use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use App\Models\User;
use App\Notifications\BillReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Asserts the header through the spool rather than poking at MailMessage internals:
 * exhaust the budget, send the notification, read the priority the spool recorded.
 * That exercises the real path the priority is actually used on.
 */
class NotificationPriorityTest extends TestCase
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

        for ($i = 0; $i < EmailBudget::DAILY_LIMIT; $i++) {
            EmailBudget::claim();
        }
    }

    public function test_a_bill_reminder_is_spooled_at_priority_eighty(): void
    {
        User::factory()->create()->notify(new BillReminder(50.0, 1));

        $this->assertSame(80, QueuedEmail::first()->priority);
    }
}
