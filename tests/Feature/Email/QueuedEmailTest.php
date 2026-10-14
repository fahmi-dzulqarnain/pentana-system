<?php

namespace Tests\Feature\Email;

use App\Models\QueuedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class QueuedEmailTest extends TestCase
{
    use RefreshDatabase;

    private function email(string $subject = 'Hello', string $to = 'member@example.com'): Email
    {
        return (new Email)
            ->from('noreply@pentana.test')
            ->to($to)
            ->subject($subject)
            ->text('Body text');
    }

    public function test_spooling_captures_subject_recipients_and_sender(): void
    {
        $email = $this->email('Lunch published', 'ali@example.com');

        $queued = QueuedEmail::spool($email, Envelope::create($email));

        $this->assertSame('Lunch published', $queued->subject);
        $this->assertSame(['ali@example.com'], $queued->recipients);
        $this->assertSame('noreply@pentana.test', $queued->sender);
        $this->assertSame(QueuedEmail::DEFAULT_PRIORITY, $queued->priority);
        $this->assertSame(0, $queued->attempts);
        $this->assertNull($queued->sent_at);
    }

    public function test_the_stored_mime_round_trips_byte_for_byte(): void
    {
        // Symfony regenerates Message-ID on every toString(), so comparing two renders of
        // the same Email proves nothing. What matters is that the ONE render captured at
        // spool time survives storage and comes back out unchanged - that is what gets
        // sent when the queue drains.
        $email = $this->email();

        $queued = QueuedEmail::spool($email, Envelope::create($email));
        $stored = $queued->mime;

        $this->assertSame($stored, $queued->fresh()->rawMessage()->toString());
        $this->assertSame($stored, $queued->fresh()->rawMessage()->toString(), 'repeated reads must be stable');
        $this->assertStringContainsString('Subject: Hello', $stored);
        $this->assertStringContainsString('Body text', $stored);
    }

    public function test_priority_is_read_from_the_header_when_present(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Pentana-Priority', '10');

        $queued = QueuedEmail::spool($email, Envelope::create($email));

        $this->assertSame(10, $queued->priority);
    }

    public function test_spooling_with_an_error_records_one_attempt(): void
    {
        $email = $this->email();

        $queued = QueuedEmail::spool($email, Envelope::create($email), 'Connection refused');

        $this->assertSame(1, $queued->attempts);
        $this->assertSame('Connection refused', $queued->last_error);
        $this->assertNull($queued->failed_at);
    }

    public function test_next_pending_orders_by_priority_then_age(): void
    {
        $older = $this->email('older');
        $newer = $this->email('newer');
        $urgent = $this->email('urgent');
        $urgent->getHeaders()->addTextHeader('X-Pentana-Priority', '10');

        QueuedEmail::spool($older, Envelope::create($older))->forceFill(['created_at' => now()->subHour()])->save();
        QueuedEmail::spool($newer, Envelope::create($newer));
        QueuedEmail::spool($urgent, Envelope::create($urgent));

        $this->assertSame('urgent', QueuedEmail::nextPending()->subject);
    }

    public function test_next_pending_skips_sent_and_exhausted_messages(): void
    {
        $sent = $this->email('already sent');
        QueuedEmail::spool($sent, Envelope::create($sent))->markSent();

        $dead = $this->email('exhausted');
        $deadRow = QueuedEmail::spool($dead, Envelope::create($dead));
        $deadRow->forceFill(['attempts' => QueuedEmail::MAX_ATTEMPTS, 'failed_at' => now()])->save();

        $this->assertNull(QueuedEmail::nextPending());
    }

    public function test_mark_failed_stamps_failed_at_on_the_final_attempt(): void
    {
        $email = $this->email();
        $queued = QueuedEmail::spool($email, Envelope::create($email));

        $queued->markFailed('boom');
        $this->assertNull($queued->fresh()->failed_at);

        $queued->markFailed('boom');
        $queued->markFailed('boom');

        $this->assertSame(3, $queued->fresh()->attempts);
        $this->assertNotNull($queued->fresh()->failed_at);
    }

    public function test_a_subject_longer_than_the_column_is_truncated(): void
    {
        // An activity title may be 255 chars, so "New activity: {title}" reaches 269.
        // On Postgres an untruncated insert throws SQLSTATE 22001 and the mail is lost.
        $email = $this->email(str_repeat('a', 300));

        $queued = QueuedEmail::spool($email, Envelope::create($email));

        $this->assertLessThanOrEqual(255, mb_strlen($queued->subject));
    }

    public function test_the_envelope_is_rebuilt_from_stored_addresses(): void
    {
        $email = $this->email('x', 'ali@example.com');
        $queued = QueuedEmail::spool($email, Envelope::create($email));

        $envelope = $queued->envelope();

        $this->assertSame('noreply@pentana.test', $envelope->getSender()->getAddress());
        $this->assertEquals([new Address('ali@example.com')], $envelope->getRecipients());
    }
}
