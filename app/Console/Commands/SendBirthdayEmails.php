<?php

namespace App\Console\Commands;

use App\Models\AutoEmail;
use App\Models\User;
use App\Notifications\BirthdayWish;
use Illuminate\Console\Command;

class SendBirthdayEmails extends Command
{
    protected $signature = 'app:send-birthday-emails';

    protected $description = 'Email the active birthday template to members whose birthday is today';

    public function handle(): int
    {
        $template = AutoEmail::where('type', 'birthday')->where('is_active', true)->latest()->first();

        if (! $template) {
            $this->info('No active birthday email template; nothing sent.');

            return self::SUCCESS;
        }

        $sent = User::enabled()->whereNotNull('birthday')->get()
            ->filter(fn (User $user) => $user->birthday->isBirthday())
            ->reject(fn (User $user) => $user->birthday_email_sent_at?->year === now()->year)
            ->each(function (User $user) use ($template) {
                // Blank subject/body fall back to the built-in, translated birthday message.
                $subject = filled($template->subject) ? str_replace('{name}', $user->name, $template->subject) : null;
                $body = filled(strip_tags((string) $template->body)) ? str_replace('{name}', e($user->name), $template->body) : null;
                $user->notify(new BirthdayWish($subject, $body));
                $user->forceFill(['birthday_email_sent_at' => now()])->save();
            })
            ->count();

        $this->info("Sent {$sent} birthday email(s).");

        return self::SUCCESS;
    }
}
