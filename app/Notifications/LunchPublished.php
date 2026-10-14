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

class LunchPublished extends Notification
{
    use PushesViaApn;

    // Sent synchronously (not ShouldQueue): this deployment has no queue worker, so inline
    // delivery guarantees the bell + logged email actually fire on publish. Re-add ShouldQueue
    // (and run `queue:work`) if the recipient list ever grows enough to warrant async fan-out.
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
        return 'Lunch published: '.($this->lunch->catererMeal?->name ?? 'Menu');
    }

    private function pushBody(): string
    {
        return 'Order by '.$this->lunch->deadline->format('g:i A, D j M').'.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Lunch menu for '.$this->lunch->date->format('D, j M'))
            ->greeting('Lunch is on the menu')
            ->line(($this->lunch->catererMeal?->name ?? 'A menu').' has been published for '.$this->lunch->date->format('l, j M').'.')
            ->line('Order by '.$this->lunch->deadline->format('g:i A, D j M').'.')
            ->action('Place your order', MyLunch::getUrl(panel: 'account'))
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
            ->icon('heroicon-o-ticket')
            ->success()
            ->actions([
                Action::make('order')->label('Order now')->url(MyLunch::getUrl(panel: 'account'))->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
