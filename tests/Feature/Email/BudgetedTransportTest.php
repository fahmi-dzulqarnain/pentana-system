<?php

namespace Tests\Feature\Email;

use App\Mail\BudgetedTransport;
use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class BudgetedTransportTest extends TestCase
{
    use RefreshDatabase;

    private function email(string $to = 'member@example.com'): Email
    {
        return (new Email)->from('noreply@pentana.test')->to($to)->subject('Hi')->text('Body');
    }

    private function recordingTransport(): TransportInterface
    {
        return new class implements TransportInterface
        {
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sent[] = $message;

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'recording://';
            }
        };
    }

    private function throwingTransport(): TransportInterface
    {
        return new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new RuntimeException('550 domain is not verified');
            }

            public function __toString(): string
            {
                return 'throwing://';
            }
        };
    }

    public function test_under_budget_the_message_reaches_the_inner_transport(): void
    {
        $inner = $this->recordingTransport();
        $email = $this->email();

        (new BudgetedTransport($inner))->send($email, Envelope::create($email));

        $this->assertCount(1, $inner->sent);
        $this->assertSame(0, QueuedEmail::count());
        $this->assertSame(1, EmailBudget::usedToday());
    }

    public function test_at_budget_the_message_is_spooled_and_never_sent(): void
    {
        for ($i = 0; $i < EmailBudget::DAILY_LIMIT; $i++) {
            EmailBudget::claim();
        }

        $inner = $this->recordingTransport();
        $email = $this->email('ali@example.com');

        (new BudgetedTransport($inner))->send($email, Envelope::create($email));

        $this->assertCount(0, $inner->sent);
        $this->assertSame(1, QueuedEmail::count());
        $this->assertSame(['ali@example.com'], QueuedEmail::first()->recipients);
        $this->assertSame(EmailBudget::DAILY_LIMIT, EmailBudget::usedToday());
    }

    public function test_a_throwing_send_releases_the_slot_and_spools(): void
    {
        $email = $this->email();

        (new BudgetedTransport($this->throwingTransport()))->send($email, Envelope::create($email));

        $this->assertSame(0, EmailBudget::usedToday(), 'a refused send must not consume a slot');
        $this->assertSame(1, QueuedEmail::count());
        $this->assertSame(1, QueuedEmail::first()->attempts);
        $this->assertStringContainsString('not verified', QueuedEmail::first()->last_error);
    }

    public function test_a_throwing_send_does_not_propagate(): void
    {
        $email = $this->email();

        $result = (new BudgetedTransport($this->throwingTransport()))->send($email, Envelope::create($email));

        $this->assertNull($result);
    }

    public function test_a_spool_failure_does_not_propagate_to_the_caller(): void
    {
        // The whole point of this transport is that nothing escapes it into a publish
        // action. A spool that throws (bad column, DB blip) must not reach the admin.
        Schema::drop('queued_emails');

        for ($i = 0; $i < EmailBudget::DAILY_LIMIT; $i++) {
            EmailBudget::claim();
        }

        $email = $this->email();

        $result = (new BudgetedTransport($this->recordingTransport()))->send($email, Envelope::create($email));

        $this->assertNull($result);
    }

    public function test_one_message_to_several_recipients_counts_as_one_send(): void
    {
        $email = (new Email)
            ->from('noreply@pentana.test')
            ->to('a@example.com', 'b@example.com', 'c@example.com')
            ->subject('Hi')
            ->text('Body');

        (new BudgetedTransport($this->recordingTransport()))->send($email, Envelope::create($email));

        $this->assertSame(1, EmailBudget::usedToday());
    }
}
