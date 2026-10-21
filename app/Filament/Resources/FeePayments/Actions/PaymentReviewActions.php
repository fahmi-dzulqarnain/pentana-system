<?php

namespace App\Filament\Resources\FeePayments\Actions;

use App\Exceptions\PaymentNotPendingException;
use App\Models\Payment\PaymentProof;
use App\Notifications\PaymentRejected;
use App\Notifications\PaymentVerified;
use App\Services\FinanceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Notifications\Notification as BaseNotification;

/**
 * Verify and Reject, shared by the payments list and the payment's own page so the
 * admin can decide right where they inspect the proof.
 */
class PaymentReviewActions
{
    public static function verify(): Action
    {
        return Action::make('verifyPayment')
            ->label(__('Verify payment'))
            ->color('success')
            ->icon('heroicon-o-check-badge')
            ->modalHeading(__('Verify payment'))
            ->modalSubmitActionLabel(__('Verify payment'))
            ->modalDescription(fn (PaymentProof $record) => __('Enter the amount that actually reached the account. The member claimed MYR :amount and will be notified.', [
                'amount' => number_format((float) $record->amount_claimed, 2),
            ]))
            ->schema([
                TextInput::make('actual_amount')
                    ->label(__('Actual amount'))
                    ->prefix('MYR')
                    ->numeric()
                    ->minValue(0.01)
                    ->default(fn (PaymentProof $record) => $record->amount_claimed)
                    ->required(),
            ])
            ->action(function (Action $action, PaymentProof $record, array $data, FinanceService $service) {
                try {
                    $service->verifyAndAllocate($record, (float) $data['actual_amount']);
                } catch (PaymentNotPendingException) {
                    Notification::make()->warning()->title(__('This payment was already reviewed.'))->send();
                    $action->halt();
                }

                self::reportOutcome(__('Payment verified'), self::notifyMember($record, new PaymentVerified($record->fresh())));
            })
            ->visible(fn (PaymentProof $record) => self::reviewable($record));
    }

    public static function reject(): Action
    {
        return Action::make('rejectPayment')
            ->label(__('Reject payment'))
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->modalHeading(__('Reject payment'))
            ->modalSubmitActionLabel(__('Reject payment'))
            ->modalDescription(__('The member is notified with this reason and can submit a new proof.'))
            ->schema([
                Textarea::make('admin_note')
                    ->label(__('Reason for rejection'))
                    ->required(),
            ])
            ->action(function (PaymentProof $record, array $data) {
                // A rejected proof is never allocated, so no credit is granted.
                $record->update([
                    'status' => 'rejected',
                    'admin_note' => $data['admin_note'],
                ]);

                self::reportOutcome(__('Payment rejected'), self::notifyMember($record, new PaymentRejected($record)));
            })
            ->visible(fn (PaymentProof $record) => self::reviewable($record));
    }

    /**
     * The decision is already saved; a delivery failure must not undo it or hide it from
     * the admin, so it is reported and surfaced instead.
     */
    private static function notifyMember(PaymentProof $record, BaseNotification $notification): bool
    {
        return rescue(function () use ($record, $notification) {
            $record->user->notify($notification);

            return true;
        }, false);
    }

    private static function reportOutcome(string $title, bool $notified): void
    {
        Notification::make()->success()->title($title)
            ->body($notified ? __('The member has been notified.') : null)
            ->send();

        if (! $notified) {
            Notification::make()->warning()->title(__('The member could not be notified.'))->send();
        }
    }

    private static function reviewable(PaymentProof $record): bool
    {
        return $record->status === 'pending' && (bool) auth()->user()?->canManageFinance();
    }
}
