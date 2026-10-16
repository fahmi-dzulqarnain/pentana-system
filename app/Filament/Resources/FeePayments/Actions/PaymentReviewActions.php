<?php

namespace App\Filament\Resources\FeePayments\Actions;

use App\Models\Payment\PaymentProof;
use App\Notifications\PaymentRejected;
use App\Services\FinanceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

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
            ->modalDescription(fn (PaymentProof $record) => __('Enter the amount that actually reached the account. The member claimed MYR :amount.', [
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
            ->action(function (PaymentProof $record, array $data, FinanceService $service) {
                $service->verifyAndAllocate($record, (float) $data['actual_amount']);

                Notification::make()->success()->title(__('Payment verified'))->send();
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

                // The rejection is already saved; a delivery failure must not undo it or
                // hide it from the admin, so it is reported and surfaced instead.
                $notified = rescue(function () use ($record) {
                    $record->user->notify(new PaymentRejected($record));

                    return true;
                }, false);

                Notification::make()->success()->title(__('Payment rejected'))
                    ->body($notified ? __('The member has been notified.') : null)
                    ->send();

                if (! $notified) {
                    Notification::make()->warning()->title(__('The member could not be notified.'))->send();
                }
            })
            ->visible(fn (PaymentProof $record) => self::reviewable($record));
    }

    private static function reviewable(PaymentProof $record): bool
    {
        return $record->status === 'pending' && (bool) auth()->user()?->is_admin;
    }
}
