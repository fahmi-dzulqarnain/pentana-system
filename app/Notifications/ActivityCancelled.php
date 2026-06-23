<?php

namespace App\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;

class ActivityCancelled extends ActivityNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Activity cancelled: '.$this->activity->title)
            ->greeting('Activity cancelled')
            ->line($this->activity->title.' scheduled for '.$this->whenLabel().' has been cancelled.')
            ->action('View activities', $this->url());
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Activity cancelled: '.$this->activity->title)
            ->body($this->whenLabel().$this->whereSuffix())
            ->icon('heroicon-o-x-circle')
            ->warning()
            ->actions([
                Action::make('view')->label('View')->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
