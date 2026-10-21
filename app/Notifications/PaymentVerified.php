<?php

namespace App\Notifications;

use App\Filament\Resources\FeePayments\FeePaymentResource;
use App\Mail\BrandedMail;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\QueuedEmail;
use App\Notifications\Concerns\PushesViaApn;
use App\Services\FinanceService;
use App\Services\ReceiptPdf;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnMessage;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Tells a member their payment proof was verified: how much was accepted, which months it
 * paid for, and where their balance now stands. Sent INLINE right after the allocation, so
 * the figures read from the database are the ones the admin just produced.
 */
class PaymentVerified extends Notification
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
        return ApnMessage::create()->title($this->title())->body($this->summary());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new BrandedMail)
            ->tone('payment', '✅', __('Payments'))
            ->subject(__('Your payment has been verified'))
            ->greeting($this->title())
            ->line(__('We verified your payment of MYR :amount.', ['amount' => $this->money($this->verified())]));

        if (round($this->verified(), 2) !== round((float) $this->payment->amount_claimed, 2)) {
            $mail->line(__('You entered MYR :claimed; MYR :amount is what reached the account.', [
                'claimed' => $this->money((float) $this->payment->amount_claimed),
                'amount' => $this->money($this->verified()),
            ]));
        }

        if ($months = $this->months()) {
            $mail->line(__('It paid for: :months.', ['months' => $months]));
        }

        $credit = (float) $this->payment->user->credit;
        if ($credit > 0) {
            $mail->line(__('You have MYR :credit in credit, which will go towards your next bills.', ['credit' => $this->money($credit)]));
        }

        $outstanding = FinanceService::outstanding(FeePeriod::forMember($this->payment->user_id)->get());
        $mail->line($outstanding > 0
            ? __('MYR :amount is still outstanding.', ['amount' => $this->money($outstanding)])
            : __('You have no outstanding dues.'));

        $this->attachReceipt($mail);

        return $mail
            ->action(__('View my payment'), $this->url())
            ->withSymfonyMessage(function (SymfonyEmail $message) {
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '60');
            });
    }

    /** Attach the receipt PDF when one exists; a rendering failure is reported but never stops the email. */
    private function attachReceipt(MailMessage $mail): void
    {
        $receipt = $this->payment->receipt;
        if (! $receipt) {
            return;
        }

        $pdf = rescue(fn () => app(ReceiptPdf::class)->render($receipt), null, report: true);
        if ($pdf !== null) {
            $mail->attachData($pdf, $receipt->filename(), ['mime' => 'application/pdf']);
        }
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->summary())
            ->icon('heroicon-o-check-badge')
            ->success()
            ->actions([
                Action::make('view')->label(__('View my payment'))->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    private function title(): string
    {
        return __('Payment verified');
    }

    /** One line for the bell and the lock screen. */
    private function summary(): string
    {
        $months = $this->months();

        return $months
            ? __('MYR :amount, paid for :months.', ['amount' => $this->money($this->verified()), 'months' => $months])
            : __('MYR :amount, added to your credit.', ['amount' => $this->money($this->verified())]);
    }

    /** The bills this payment went towards, oldest first: "Oktober 2026, November 2026". */
    private function months(): string
    {
        return $this->payment->allocations()->with('feePeriod')->get()
            ->pluck('feePeriod.month_date')->filter()->sort()
            ->map(fn ($month) => $month->translatedFormat('F Y'))
            ->unique()->implode(', ');
    }

    private function verified(): float
    {
        return (float) ($this->payment->amount_verified ?? $this->payment->amount_claimed);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2);
    }

    private function url(): string
    {
        return FeePaymentResource::getUrl('view', ['record' => $this->payment], panel: 'account');
    }
}
