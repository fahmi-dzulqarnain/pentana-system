<?php

namespace App\Filament\Resources\PaymentReceipts\Actions;

use App\Models\Payment\PaymentReceipt;
use App\Services\ReceiptPdf;
use Closure;
use Filament\Actions\Action;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadReceiptAction
{
    /**
     * @param  Closure  $receipt  Resolves the PaymentReceipt (or null) from the action's record.
     */
    public static function make(Closure $receipt): Action
    {
        return Action::make('downloadReceipt')
            ->label(__('Download receipt'))
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (Action $action): bool => self::allowed($action->evaluate($receipt)))
            ->action(function (Action $action) use ($receipt): StreamedResponse {
                // The record is re-resolved here: visible() is not an authorization boundary.
                $r = $action->evaluate($receipt);
                abort_unless(self::allowed($r), 403);

                return response()->streamDownload(
                    fn () => print (app(ReceiptPdf::class)->render($r)),
                    $r->filename(),
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }

    private static function allowed(mixed $receipt): bool
    {
        $user = auth()->user();

        return $user !== null
            && $receipt instanceof PaymentReceipt
            && ($user->canManageFinance() || ($receipt->user_id !== null && $receipt->user_id === $user->id));
    }
}
