<?php

namespace App\Notifications;

use App\Models\QueuedEmail;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email as SymfonyEmail;

class ActivityReminder extends ActivityNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Reminder: '.$this->activity->title)
            ->greeting('Coming up soon')
            ->line($this->activity->title.' is on '.$this->whenLabel().'.');

        if ($this->activity->location) {
            $mail->line('Where: '.$this->activity->location);
        }

        return $mail->action('View activities', $this->url())
            ->withSymfonyMessage(function (SymfonyEmail $message) {
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '20');
            });
    }

    protected function pushTitle(): string
    {
        return 'Reminder: '.$this->activity->title;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->pushTitle())
            ->body($this->pushBody())
            ->icon('heroicon-o-bell-alert')
            ->info()
            ->actions([
                Action::make('view')->label('View')->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
