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

    protected $signature = 'app:drain-email-queue';

    protected $description = 'Send queued email within the remaining daily budget';

    public function handle(): int
    {
        $transport = Mail::mailer(config('mail.mailers.budgeted.inner', 'smtp'))->getSymfonyTransport();

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

            try {
                $transport->send($queued->rawMessage(), $queued->envelope());
                $queued->markSent();
                $sent++;
            } catch (Throwable $e) {
                EmailBudget::release();
                $queued->markFailed($e->getMessage());
                $attempted[] = $queued->id;
                report($e);
            }
        }

        $this->info("Sent {$sent} queued email(s).");

        return self::SUCCESS;
    }
}
