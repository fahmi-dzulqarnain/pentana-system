<?php

namespace Tests\Feature\Receipts;

use App\Models\Payment\PaymentReceipt;
use App\Services\ReceiptPdf;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class ReceiptPdfTest extends TestCase
{
    private const FOOTER = 'This is a computer-generated receipt; no signature is required.';

    private function receipt(array $overrides = []): PaymentReceipt
    {
        return (new PaymentReceipt)->forceFill(array_merge([
            'number' => 'PEN-2026-0007',
            'year' => 2026,
            'sequence' => 7,
            'user_id' => 1,
            'member_name' => 'Ahmad bin Ismail',
            'member_email' => 'ahmad@example.com',
            'amount_received' => '30.00',
            'credit_used' => '20.00',
            'credit_added' => '20.00',
            'lines' => [
                ['label' => 'Monthly fee', 'month' => 'September 2026', 'amount' => '30.00'],
            ],
            'verified_at' => '2026-10-21 10:00:00',
            'voided_at' => null,
            'created_at' => '2026-10-21 10:00:00',
        ], $overrides));
    }

    private function pdf(): ReceiptPdf
    {
        return app(ReceiptPdf::class);
    }

    public function test_html_shows_the_snapshot(): void
    {
        $html = $this->pdf()->html($this->receipt(['credit_added' => '0.00', 'amount_received' => '10.00']));

        foreach ([
            'PEN-2026-0007', 'Monthly fee: September 2026', 'Less: existing credit applied',
            '-20.00', 'Total received', '10.00', self::FOOTER, 'PAID', '21 Oct 2026',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('VOID', $html);
        $this->assertStringNotContainsString('Added to credit', $html);
    }

    public function test_html_shows_added_to_credit(): void
    {
        $html = $this->pdf()->html($this->receipt(['credit_used' => '0.00', 'credit_added' => '10.00', 'amount_received' => '40.00']));

        $this->assertStringContainsString('Added to credit', $html);
        $this->assertStringNotContainsString('Less: existing credit applied', $html);
    }

    public function test_html_is_english_for_a_malay_member(): void
    {
        App::setLocale('ms');
        $html = $this->pdf()->html($this->receipt([
            'lines' => [['label' => 'Monthly fee', 'month' => 'October 2026', 'amount' => '30.00']],
        ]));

        $this->assertStringContainsString('October 2026', $html);
        $this->assertStringContainsString('Issued', $html);
        $this->assertStringContainsString('Received from', $html);
        $this->assertStringNotContainsString('Oktober', $html);
        $this->assertSame('ms', App::getLocale());
    }

    public function test_voided_html(): void
    {
        $html = $this->pdf()->html($this->receipt(['voided_at' => '2026-10-23 09:00:00']));

        $this->assertStringContainsString('VOID', $html);
        $this->assertStringContainsString('Voided on 23 Oct 2026', $html);
        $this->assertStringContainsString('This receipt is no longer valid.', $html);
        $this->assertStringNotContainsString('>PAID<', $html);
    }

    public function test_receipt_for_a_deleted_member_still_renders(): void
    {
        $receipt = $this->receipt(['user_id' => null]);

        $this->assertStringStartsWith('%PDF', $this->pdf()->render($receipt));
        $this->assertStringContainsString('Ahmad bin Ismail', $this->pdf()->html($receipt));
    }

    public function test_pdf_is_small(): void
    {
        $this->assertLessThan(100_000, strlen($this->pdf()->render($this->receipt())));
    }
}
