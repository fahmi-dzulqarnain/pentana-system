<?php

namespace App\Notifications;

use App\Mail\BrandedMail;
use App\Models\QueuedEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * The "forgot password" email, standing in for Filament's own (bound in AppServiceProvider).
 * Filament's is ShouldQueue and there is no queue worker, so it would never leave; this one
 * sends inline, in the branded layout, ahead of everything else in the spool.
 */
class PasswordResetLink extends ResetPassword
{
    /** Set by Filament's request page: its panel's reset URL for this token. */
    public string $url;

    protected function resetUrl($notifiable): string
    {
        return $this->url;
    }

    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new BrandedMail)
            ->tone('default', '🔑', __('Your account'))
            ->subject(__('Reset your :app password', ['app' => config('app.name')]))
            ->greeting(__('Reset your password'))
            ->line(__('Someone asked to reset the password for your account. If it was you, choose a new one below.'))
            ->action(__('Choose a new password'), $url)
            ->line(__('This link works once and expires in :count minutes.', ['count' => $minutes]))
            ->line(__('If you did not ask for this, ignore this email: your password stays the same.'))
            ->withSymfonyMessage(function (SymfonyEmail $message) use ($minutes) {
                // Someone is locked out and waiting, and a token that expired in the spool is useless.
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '10');
                $message->getHeaders()->addTextHeader(QueuedEmail::EXPIRES_HEADER, now()->addMinutes($minutes)->toIso8601String());
            });
    }
}
