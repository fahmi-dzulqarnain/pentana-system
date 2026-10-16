<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TestEmailSender;
use Illuminate\Console\Command;

class SendTestEmails extends Command
{
    protected $signature = 'app:send-test-emails {email : Address of an existing member} {--only= : Comma-separated types, e.g. lunch-published,birthday}';

    protected $description = 'Send a [Test] sample of every email type to one member';

    public function handle(TestEmailSender $sender): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No member has that email address.');

            return self::FAILURE;
        }

        $only = $this->option('only') ? array_map('trim', explode(',', $this->option('only'))) : null;
        $sent = $sender->send($user, $only);

        foreach ($sent as $type) {
            $this->line("Sent {$type}");
        }
        $this->info(count($sent)." test email(s) sent to {$user->email} in '{$user->preferredLocale()}'.");

        return self::SUCCESS;
    }
}
