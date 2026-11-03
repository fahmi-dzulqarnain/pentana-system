<?php

namespace App\Support;

use App\Models\User;

/** The club's bank transfer details, plus the member's own reference, in one shape for the portal and API. */
final class PaymentDetails
{
    /** @return array{bank_name: string, account_number: string, account_name: string, reference: string, qr_url: string} */
    public static function for(User $user): array
    {
        return [
            'bank_name' => config('payment.bank_name'),
            'account_number' => config('payment.account_number'),
            'account_name' => config('payment.account_name'),
            'reference' => $user->payment_reference,
            'qr_url' => asset(config('payment.qr_image')),
        ];
    }
}
