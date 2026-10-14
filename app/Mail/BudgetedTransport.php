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
            QueuedEmail::spool($message, $envelope);

            return null;
        }

        try {
            return $this->inner->send($message, $envelope);
        } catch (Throwable $e) {
            // The provider did not accept it, so the slot must not count.
            EmailBudget::release();
            QueuedEmail::spool($message, $envelope, $e->getMessage());
            report($e);

            // Deliberately swallowed: notifications send inline, so propagating here would
            // turn a mail outage into "admins cannot publish". The failure stays visible
            // through EmailBudgetStats and the log.
            return null;
        }
    }

    public function __toString(): string
    {
        return 'budgeted://'.$this->inner;
    }
}
