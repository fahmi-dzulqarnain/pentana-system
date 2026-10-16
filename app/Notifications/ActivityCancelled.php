<?php

namespace App\Notifications;

use App\Mail\BrandedMail;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;

class ActivityCancelled extends ActivityNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new BrandedMail)
            ->tone('alert', '📅', __('Activities'))
            ->subject($this->pushTitle())
            ->greeting(__('Activity cancelled'))
            ->line(__(':activity scheduled for :when has been cancelled.', ['activity' => $this->activity->title, 'when' => $this->whenLabel()]))
            ->action(__('View activities'), $this->url());
    }

    protected function pushTitle(): string
    {
        return __('Activity cancelled: :activity', ['activity' => $this->activity->title]);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->pushTitle())
            ->body($this->pushBody())
            ->icon('heroicon-o-x-circle')
            ->warning()
            ->actions([
                Action::make('view')->label(__('View'))->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
