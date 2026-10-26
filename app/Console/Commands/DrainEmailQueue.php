<?php

namespace App\Console\Commands;

use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends queued email that did not fit an earlier budget.
 *
 * Runs every minute from the scheduler. Sends through the INNER transport, not the
 * budgeted one, because this command claims the slot itself - going through the wrapper
 * would claim twice and re-spool the message it is trying to drain.
 */
class DrainEmailQueue extends Command
{
    public const BATCH = 25;

    /**
     * Transports that cannot send a RawMessage: they call MessageConverter::toEmail()
     * and throw. Attempting the send would burn all three attempts and destroy the
     * queue, so refuse loudly instead - this is a misconfiguration, not a bad minute.
     */
    public const RAW_INCAPABLE = ['resend', 'ses', 'ses-v2', 'postmark', 'mailgun', 'sparkpost'];

    protected $signature = 'app:drain-email-queue';

    protected $description = 'Send queued email within the remaining daily budget';

    public function handle(): int
    {
        $innerName = config('mail.mailers.budgeted.inner', 'smtp');
        $innerTransport = config("mail.mailers.{$innerName}.transport");

        if (in_array($innerTransport, self::RAW_INCAPABLE, true)) {
            $this->error("Cannot drain: the '{$innerName}' mailer uses the '{$innerTransport}' transport, which cannot send stored MIME. Point MAIL_INNER_MAILER at an SMTP mailer.");

            return self::FAILURE;
        }

        $transport = Mail::mailer($innerName)->getSymfonyTransport();

        $expired = QueuedEmail::failExpired();

        $sent = 0;
        $attempted = [];

        for ($i = 0; $i < self::BATCH; $i++) {
            $queued = QueuedEmail::nextPending($attempted);

            if (! $queued) {
                break;
            }

            if (! EmailBudget::claim()) {
                break;
            }

            // Take the row before sending: withoutOverlapping() only guards the scheduled
            // run, and a hand-run drain would otherwise send the batch a second time.
            if (! $queued->claimForSending()) {
                EmailBudget::release();
                $attempted[] = $queued->id;

                continue;
            }

            try {
                $transport->send($queued->rawMessage(), $queued->envelope());
                $sent++;
            } catch (Throwable $e) {
                EmailBudget::release();
                $queued->markFailed($e->getMessage());
                $attempted[] = $queued->id;
                report($e);
            }
        }

        $this->info("Sent {$sent} queued email(s); dropped {$expired} expired.");

        return self::SUCCESS;
    }
}
