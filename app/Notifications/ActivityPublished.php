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
            ->subject($this->pushTitle())
            ->greeting(__('A new activity'))
            ->line(__(':activity is on :when.', ['activity' => $this->activity->title, 'when' => $this->whenLabel()]));

        if ($this->activity->location) {
            $mail->line(__('Where: :location', ['location' => $this->activity->location]));
        }

        return $mail->action(__('View activities'), $this->url());
    }

    protected function pushTitle(): string
    {
        return __('New activity: :activity', ['activity' => $this->activity->title]);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->pushTitle())
            ->body($this->pushBody())
            ->icon('heroicon-o-sparkles')
            ->success()
            ->actions([
                Action::make('view')->label(__('View'))->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
