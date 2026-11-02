<?php

namespace App\Notifications;

use App\Mail\BrandedMail;
use App\Models\QueuedEmail;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email as SymfonyEmail;

class ActivityReminder extends ActivityNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new BrandedMail)
            ->tone('activity', '⏰', __('Activities'))
            ->subject($this->pushTitle())
            ->greeting(__('Coming up soon'))
            ->line(__(':activity is on :when.', ['activity' => $this->activity->title, 'when' => $this->whenLabel()]));

        if ($this->activity->location) {
            $mail->line(__('Where: :location', ['location' => $this->activity->location]));
        }

        return $mail->action(__('View activities'), $this->url())
            ->withSymfonyMessage(function (SymfonyEmail $message) {
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '20');
                // A coming-soon activity has no start yet, so its announcement never goes stale.
                if ($this->activity->starts_at) {
                    $message->getHeaders()->addTextHeader(QueuedEmail::EXPIRES_HEADER, $this->activity->starts_at->toIso8601String());
                }
            });
    }

    protected function pushTitle(): string
    {
        return __('Reminder: :activity', ['activity' => $this->activity->title]);
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
                Action::make('view')->label(__('View'))->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
