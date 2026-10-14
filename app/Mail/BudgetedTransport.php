<?php

namespace App\Mail;

use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Wraps the real mail transport and refuses to exceed Resend's free-tier limits.
 *
 * Every outbound message passes through Laravel's mailer, so this one place covers
 * notifications, Mail::raw, password resets and anything Filament sends - no sender needs
 * to know a cap exists. The database and APNs notification channels are untouched.
 */
class BudgetedTransport implements TransportInterface
{
    public function __construct(private readonly TransportInterface $inner) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (! EmailBudget::claim()) {
            $this->spoolSafely($message, $envelope);

            return null;
        }

        try {
            return $this->inner->send($message, $envelope);
        } catch (Throwable $e) {
            // The provider did not accept it, so the slot must not count.
            EmailBudget::release();
            $this->spoolSafely($message, $envelope, $e->getMessage());
            report($e);

            // Deliberately swallowed: notifications send inline, so propagating here would
            // turn a mail outage into "admins cannot publish". The failure stays visible
            // through EmailBudgetStats and the log.
            return null;
        }
    }

    /**
     * Spooling is the last line of defence, so it gets its own guard: a failure here
     * (schema drift, a DB blip) must not escape into the request that published a lunch.
     * Losing one message is bad; taking down publishing is worse.
     */
    private function spoolSafely(RawMessage $message, ?Envelope $envelope, ?string $error = null): void
    {
        try {
            QueuedEmail::spool($message, $envelope, $error);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function __toString(): string
    {
        return 'budgeted://'.$this->inner;
    }
}
