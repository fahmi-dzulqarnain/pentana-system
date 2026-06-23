<?php

namespace App\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;

class ActivityPublished extends ActivityNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('New activity: '.$this->activity->title)
            ->greeting('A new activity')
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
            ->title('New activity: '.$this->activity->title)
            ->body($this->whenLabel().$this->whereSuffix())
            ->icon('heroicon-o-sparkles')
            ->success()
            ->actions([
                Action::make('view')->label('View')->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
