<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BirthdayWish extends Notification
{
    public function __construct(public string $subject, public string $body) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // $body is RichEditor HTML - render it unescaped via a custom mail view
        // (MailMessage->line() would escape the tags).
        return (new MailMessage)
            ->subject($this->subject)
            ->view('mail.auto-email', ['body' => $this->body]);
    }
}
