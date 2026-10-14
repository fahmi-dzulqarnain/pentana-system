<?php

namespace App\Notifications;

use App\Models\QueuedEmail;
use App\Notifications\Concerns\PushesViaApn;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Pages\Dashboard;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnMessage;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Twice-monthly nudge for members with an outstanding balance. Delivered INLINE
 * (not ShouldQueue): this deployment has no queue worker, so the bell + logged
 * email fire synchronously when the scheduled command runs.
 */
class BillReminder extends Notification
{
    use PushesViaApn;

    public function __construct(public float $outstanding, public int $unpaidCount) {}

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
        return __('Outstanding dues reminder');
    }

    private function pushBody(): string
    {
        return __('MYR :amount outstanding across :count bill(s).', $this->amounts());
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Reminder: outstanding dues'))
            ->greeting(__('Friendly reminder'))
            ->line(__('You have MYR :amount outstanding across :count bill(s).', $this->amounts()))
            ->line(__('Once you have transferred, upload a payment proof and an admin will verify it.'))
            ->action(__('View my bills'), $this->url())
            ->withSymfonyMessage(function (SymfonyEmail $message) {
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '80');
            });
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->pushTitle())
            ->body($this->pushBody())
            ->icon('heroicon-o-banknotes')
            ->warning()
            ->actions([
                Action::make('view')->label(__('View bills'))->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /** @return array{amount: string, count: int} */
    private function amounts(): array
    {
        return ['amount' => number_format($this->outstanding, 2), 'count' => $this->unpaidCount];
    }

    private function url(): string
    {
        return Dashboard::getUrl(panel: 'account');
    }
}
