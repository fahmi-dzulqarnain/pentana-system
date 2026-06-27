<?php

namespace App\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;

class WaitlistPromoted extends ActivityNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're in: ".$this->activity->title)
            ->greeting("You're in!")
            ->line('A spot opened up — you are now registered for '.$this->activity->title.' on '.$this->whenLabel().'.')
            ->action('View activities', $this->url());
    }

    protected function pushTitle(): string
    {
        return "You're in: ".$this->activity->title;
    }

    protected function pushBody(): string
    {
        return 'A spot opened up — '.$this->whenLabel().$this->whereSuffix();
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->pushTitle())
            ->body($this->pushBody())
            ->icon('heroicon-o-check-badge')
            ->success()
            ->actions([
                Action::make('view')->label('View')->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
