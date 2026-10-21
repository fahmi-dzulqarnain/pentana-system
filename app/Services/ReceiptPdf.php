<?php

namespace App\Services;

use App\Models\Payment\PaymentReceipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Traits\Localizable;

/**
 * Renders a PaymentReceipt snapshot as an A4 PDF. Always English, whatever the
 * caller's locale; the locale is restored afterwards.
 */
class ReceiptPdf
{
    use Localizable;

    public function html(PaymentReceipt $receipt): string
    {
        return $this->withLocale('en', fn () => view('receipts.receipt', [
            'receipt' => $receipt,
            'logo' => $this->logoDataUri(),
        ])->render());
    }

    public function render(PaymentReceipt $receipt): string
    {
        return $this->withLocale('en', fn () => Pdf::loadHTML($this->html($receipt))
            ->setPaper('a4')
            ->setOption(['enable_font_subsetting' => true])
            ->output());
    }

    /** A downscaled copy of the brand mark; the full-size one alone would exceed the PDF size budget. */
    private function logoDataUri(): string
    {
        return 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('images/receipt-logo.png')));
    }
}
