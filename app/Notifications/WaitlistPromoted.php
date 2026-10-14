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
            ->subject($this->pushTitle())
            ->greeting(__("You're in!"))
            ->line(__('A spot opened up, so you are now registered for :activity on :when.', ['activity' => $this->activity->title, 'when' => $this->whenLabel()]))
            ->action(__('View activities'), $this->url());
    }

    protected function pushTitle(): string
    {
        return __("You're in: :activity", ['activity' => $this->activity->title]);
    }

    protected function pushBody(): string
    {
        return __('A spot opened up: :when', ['when' => $this->whenLabel()]).$this->whereSuffix();
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
                Action::make('view')->label(__('View'))->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
