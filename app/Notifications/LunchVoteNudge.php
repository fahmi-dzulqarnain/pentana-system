<?php

namespace App\Notifications;

use App\Filament\Pages\MyLunch;
use App\Models\Lunch;
use App\Notifications\Concerns\PushesViaApn;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnMessage;

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
        return 'Last call: '.($this->lunch->catererMeal?->name ?? 'lunch menu');
    }

    private function pushBody(): string
    {
        return 'Voting closes at '.$this->lunch->deadline->format('g:i A, D j M').' and you haven\'t responded.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Last call to order lunch for '.$this->lunch->date->format('D, j M'))
            ->greeting('Voting closes soon')
            ->line('You haven\'t responded to the lunch for '.$this->lunch->date->format('l, j M').'.')
            ->line('Voting closes at '.$this->lunch->deadline->format('g:i A, D j M').'.')
            ->action('Place your order', $this->url());
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
                Action::make('view')->label('Vote now')->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    private function url(): string
    {
        return MyLunch::getUrl(panel: 'account');
    }
}
