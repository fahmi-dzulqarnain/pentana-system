<?php

namespace App\Notifications;

use App\Filament\Pages\MyLunch;
use App\Models\Lunch;
use App\Models\QueuedEmail;
use App\Notifications\Concerns\PushesViaApn;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnMessage;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Last-call nudge for members who haven't voted on a lunch closing within a day.
 * Delivered INLINE (not ShouldQueue): this deployment has no queue worker.
 */
class LunchVoteNudge extends Notification
{
    use PushesViaApn;

    public function __construct(public Lunch $lunch) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->withApn($notifiable, ['database', 'mail']);
    }

    public function toApn(object $notifiable): ApnMessage
    {
        return ApnMessage::create()->title($this->pushTitle())->body($this->pushBody());
    }

    private function pushTitle(): string
    {
        return __('Last call: :menu', ['menu' => $this->lunch->catererMeal?->name ?? __('lunch menu')]);
    }

    private function pushBody(): string
    {
        return __("Voting closes at :deadline and you haven't responded.", ['deadline' => $this->deadline()]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Last call to order lunch for :date', ['date' => $this->lunch->date->translatedFormat('D, j M')]))
            ->greeting(__('Voting closes soon'))
            ->line(__("You haven't responded to the lunch for :date.", ['date' => $this->lunch->date->translatedFormat('l, j M')]))
            ->line(__('Voting closes at :deadline.', ['deadline' => $this->deadline()]))
            ->action(__('Place your order'), $this->url())
            ->withSymfonyMessage(function (SymfonyEmail $message) {
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '10');
            });
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->pushTitle())
            ->body($this->pushBody())
            ->icon('heroicon-o-clock')
            ->warning()
            ->actions([
                Action::make('view')->label(__('Vote now'))->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    private function deadline(): string
    {
        return $this->lunch->deadline->translatedFormat('g:i A, D j M');
    }

    private function url(): string
    {
        return MyLunch::getUrl(panel: 'account');
    }
}
