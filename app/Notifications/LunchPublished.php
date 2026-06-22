<?php

namespace App\Notifications;

use App\Filament\Pages\MyLunch;
use App\Models\Lunch;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LunchPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lunch $lunch) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Lunch menu for '.$this->lunch->date->format('D, j M'))
            ->greeting('Lunch is on the menu')
            ->line(($this->lunch->catererMeal?->name ?? 'A menu').' has been published for '.$this->lunch->date->format('l, j M').'.')
            ->line('Order by '.$this->lunch->deadline->format('g:i A, D j M').'.')
            ->action('Place your order', MyLunch::getUrl(panel: 'account'));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Lunch published: '.($this->lunch->catererMeal?->name ?? 'Menu'))
            ->body('Order by '.$this->lunch->deadline->format('g:i A, D j M').'.')
            ->icon('heroicon-o-ticket')
            ->success()
            ->actions([
                Action::make('order')->label('Order now')->url(MyLunch::getUrl(panel: 'account'))->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
