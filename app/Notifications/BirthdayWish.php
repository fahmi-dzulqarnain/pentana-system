<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BirthdayWish extends Notification
{
    /**
     * Without a subject/body the built-in birthday message is sent, translated into the
     * member's language. With them, the admin's own template is sent as written.
     */
    public function __construct(public ?string $subject = null, public ?string $body = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject ?: __('Happy birthday, :name! 🎂', ['name' => $notifiable->display_name]));

        if (blank($this->body)) {
            return $mail->view(
                ['html' => 'mail.birthday', 'text' => 'mail.birthday-text'],
                ['name' => $notifiable->display_name, 'url' => url('/account')],
            );
        }

        // $body is RichEditor HTML - render it unescaped via a custom mail view
        // (MailMessage->line() would escape the tags).
        return $mail->view('mail.auto-email', ['body' => $this->body]);
    }
}
