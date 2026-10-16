<?php

namespace App\Notifications;

use App\Filament\Resources\FeePayments\FeePaymentResource;
use App\Models\Payment\PaymentProof;
use App\Models\QueuedEmail;
use App\Notifications\Concerns\PushesViaApn;
use App\Providers\AppServiceProvider;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Apn\ApnMessage;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Tells a member their payment proof was rejected, with the admin's reason, so they can
 * send a new one. Delivered INLINE when the admin rejects (there is no queue worker).
 * The reason is the admin's own words and is never translated.
 */
class PaymentRejected extends Notification
{
    use PushesViaApn;

    public function __construct(public PaymentProof $payment) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->withApn($notifiable, ['database', 'mail']);
    }

    public function toApn(object $notifiable): ApnMessage
    {
        return ApnMessage::create()
            ->title($this->title())
            ->body(__('MYR :amount: :reason', ['amount' => $this->amount(), 'reason' => Str::limit($this->reason(), 120)]));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your payment could not be verified'))
            ->greeting($this->title())
            ->line(__('We could not verify your payment of MYR :amount submitted on :date.', [
                'amount' => $this->amount(),
                'date' => $this->payment->created_at->translatedFormat(AppServiceProvider::DATE_FORMAT),
            ]))
            ->line(__('Reason: :reason', ['reason' => $this->reason()]))
            ->line(__('Please upload a new payment proof, or contact the committee if you think this is a mistake.'))
            ->action(__('View my payment'), $this->url())
            ->withSymfonyMessage(function (SymfonyEmail $message) {
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '30');
            });
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            // e(): the admin's reason shows as typed, never as markup.
            ->body(e(__('MYR :amount: :reason', ['amount' => $this->amount(), 'reason' => $this->reason()])))
            ->icon('heroicon-o-x-circle')
            ->danger()
            ->actions([
                Action::make('view')->label(__('View my payment'))->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    private function title(): string
    {
        return __('Payment not verified');
    }

    private function reason(): string
    {
        return (string) $this->payment->admin_note;
    }

    private function amount(): string
    {
        return number_format((float) $this->payment->amount_claimed, 2);
    }

    private function url(): string
    {
        return FeePaymentResource::getUrl('view', ['record' => $this->payment], panel: 'account');
    }
}
