<?php

namespace App\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;

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

        return $mail->action('View activities', $this->url());
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Reminder: '.$this->activity->title)
            ->body($this->whenLabel().$this->whereSuffix())
            ->icon('heroicon-o-bell-alert')
            ->info()
            ->actions([
                Action::make('view')->label('View')->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
