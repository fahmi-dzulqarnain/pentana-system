<?php

namespace Tests\Feature\Email;

use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class DrainEmailQueueTest extends TestCase
{
    use RefreshDatabase;

    private function queue(string $subject, int $priority = 50): QueuedEmail
    {
        $email = (new Email)->from('noreply@pentana.test')->to('member@example.com')
            ->subject($subject)->text('Body');
        $email->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, (string) $priority);

        return QueuedEmail::spool($email, Envelope::create($email));
    }

    private function fakeInner(bool $throws = false): object
    {
        $transport = new class($throws) implements TransportInterface
        {
            public array $sent = [];

            public function __construct(private bool $throws) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                if ($this->throws) {
                    throw new RuntimeException('smtp down');
                }
                $this->sent[] = $message->toString();

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        };

        Mail::extend('fake-inner', fn () => $transport);
        config(['mail.mailers.fake-inner' => ['transport' => 'fake-inner']]);
        config(['mail.mailers.budgeted.inner' => 'fake-inner']);

        return $transport;
    }

    public function test_an_empty_queue_is_a_no_op(): void
    {
        $this->fakeInner();

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertSame(0, EmailBudget::usedToday(), 'an empty drain must not claim a slot');
    }

    public function test_it_sends_pending_mail_oldest_first_within_priority(): void
    {
        $inner = $this->fakeInner();

        $this->queue('bill', 80)->forceFill(['created_at' => now()->subHour()])->save();
        $this->queue('lunch', 10);

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertCount(2, $inner->sent);
        $this->assertStringContainsString('lunch', $inner->sent[0]);
        $this->assertStringContainsString('bill', $inner->sent[1]);
    }

    public function test_sent_messages_are_stamped_and_not_resent(): void
    {
        $inner = $this->fakeInner();
        $this->queue('one');

        $this->artisan('app:drain-email-queue')->assertSuccessful();
        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertCount(1, $inner->sent);
        $this->assertNotNull(QueuedEmail::first()->sent_at);
    }

    public function test_it_stops_when_the_budget_is_exhausted_and_leaves_the_rest_queued(): void
    {
        $inner = $this->fakeInner();

        for ($i = 0; $i < EmailBudget::DAILY_LIMIT - 1; $i++) {
            EmailBudget::claim();
        }

        $this->queue('first');
        $this->queue('second');

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertCount(1, $inner->sent);
        $this->assertSame(1, QueuedEmail::whereNull('sent_at')->count());
    }

    public function test_it_sends_at_most_twenty_five_per_run(): void
    {
        $inner = $this->fakeInner();

        for ($i = 0; $i < 30; $i++) {
            $this->queue("msg {$i}");
        }

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertCount(25, $inner->sent);
        $this->assertSame(5, QueuedEmail::whereNull('sent_at')->count());
    }

    public function test_a_failed_send_releases_the_slot_and_records_the_attempt(): void
    {
        $this->fakeInner(throws: true);
        $this->queue('doomed');

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertSame(0, EmailBudget::usedToday());
        $this->assertSame(1, QueuedEmail::first()->attempts);
    }

    public function test_a_failed_message_is_not_retried_until_its_backoff_elapses(): void
    {
        $this->fakeInner(throws: true);
        $this->queue('doomed');

        // Three drains inside the same minute must NOT burn all three attempts:
        // a brief provider outage would otherwise destroy the whole backlog.
        $this->artisan('app:drain-email-queue')->assertSuccessful();
        $this->artisan('app:drain-email-queue')->assertSuccessful();
        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertSame(1, QueuedEmail::first()->attempts);
        $this->assertNull(QueuedEmail::first()->failed_at);
    }

    public function test_a_failed_message_is_retried_once_its_backoff_has_passed(): void
    {
        $this->fakeInner(throws: true);
        $this->queue('doomed');

        $this->artisan('app:drain-email-queue')->assertSuccessful();
        $this->assertSame(1, QueuedEmail::first()->attempts);

        $this->travel(2)->hours();
        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertSame(2, QueuedEmail::first()->attempts);
    }

    public function test_a_message_already_claimed_for_sending_is_not_sent_twice(): void
    {
        $this->fakeInner();
        $queued = $this->queue('once');

        $this->assertTrue($queued->claimForSending());
        $this->assertFalse($queued->fresh()->claimForSending(), 'a second claim must lose');
    }

    public function test_it_refuses_to_run_when_the_inner_transport_cannot_send_raw_mime(): void
    {
        // API transports (resend, ses, postmark) call MessageConverter::toEmail() and throw
        // on a RawMessage. Attempting the send would burn attempts and destroy the queue.
        $apiTransport = new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new RuntimeException('should never be called');
            }

            public function __toString(): string
            {
                return 'api://';
            }
        };

        Mail::extend('api-ish', fn () => $apiTransport);
        config([
            'mail.mailers.api-ish' => ['transport' => 'resend'],
            'mail.mailers.budgeted.inner' => 'api-ish',
        ]);

        $this->queue('never sent');

        $this->artisan('app:drain-email-queue')->assertFailed();

        $this->assertSame(0, QueuedEmail::first()->attempts, 'nothing may be attempted');
    }

    public function test_an_exhausted_message_does_not_block_the_queue_head(): void
    {
        $inner = $this->fakeInner();

        $dead = $this->queue('dead', 10);
        $dead->forceFill(['attempts' => QueuedEmail::MAX_ATTEMPTS, 'failed_at' => now()])->save();

        $this->queue('alive', 20);

        $this->artisan('app:drain-email-queue')->assertSuccessful();

        $this->assertCount(1, $inner->sent);
        $this->assertStringContainsString('alive', $inner->sent[0]);
    }
}
