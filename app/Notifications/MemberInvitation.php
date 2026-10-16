<?php

namespace App\Notifications;

use App\Models\QueuedEmail;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Invites a member an admin created to join: what membership costs them and what it
 * includes, then how to claim the account. Mail only: an unclaimed member has never
 * signed in, so they have no bell and no device token yet.
 */
class MemberInvitation extends Notification
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You are invited to join :app', ['app' => config('app.name')]))
            ->view(['html' => 'mail.invitation', 'text' => 'mail.invitation-text'], [
                'name' => $notifiable->name,
                'email' => $notifiable->email,
                // Their own monthly fee (custom override, else their category's), so the offer is concrete.
                'fee' => $notifiable->active_fee > 0 ? number_format($notifiable->active_fee, 2) : null,
                'category' => $notifiable->memberCategory?->name,
                'expires' => ($notifiable->invitationExpiresAt() ?? now()->addDays(User::INVITATION_VALID_DAYS))->translatedFormat('j F Y'),
                // The email stays out of the URL; the member types it on the claim page.
                'url' => route('claim'),
            ])
            ->withSymfonyMessage(function (SymfonyEmail $message) {
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '40');
            });
    }
}
