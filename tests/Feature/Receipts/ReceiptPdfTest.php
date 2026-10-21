<?php

namespace Tests\Feature\Receipts;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\Payment\PaymentReceipt;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\ReceiptPdf;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Receipts here are issued through the real verify path, so the template is
 * exercised against the line shape ReceiptService actually writes.
 */
class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    private const FOOTER = 'This is a computer-generated receipt; no signature is required.';

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-21 10:00'));
        $this->member = User::factory()->create(['name' => 'Ahmad bin Ismail', 'email' => 'ahmad@example.com']);
    }

    private function bill(string $monthDate, float $amount): FeePeriod
    {
        return FeePeriod::create([
            'user_id' => $this->member->id,
            'month_date' => $monthDate,
            'amount_due' => $amount,
            'status' => 'unpaid',
        ]);
    }

    private function issue(float $amount): PaymentReceipt
    {
        $proof = PaymentProof::create([
            'user_id' => $this->member->id,
            'proof_image_path' => 'receipts/r.jpg',
            'amount_claimed' => $amount,
            'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($proof, $amount);

        return $proof->fresh()->receipt;
    }

    private function pdf(): ReceiptPdf
    {
        return app(ReceiptPdf::class);
    }

    public function test_html_shows_the_snapshot_with_old_credit_applied(): void
    {
        $this->issue(20); // no bills yet: all of it becomes credit
        $this->bill('2026-09-01', 30);
        $receipt = $this->issue(10); // 20 old credit + 10 new settles September

        $this->assertSame([['label' => 'Monthly fee: September 2026', 'month' => '2026-09-01', 'amount' => '30.00']], $receipt->lines);

        $html = $this->pdf()->html($receipt);

        foreach ([
            $receipt->number, 'Monthly fee: September 2026', '30.00', 'Less: existing credit applied',
            '-20.00', 'Total received', '10.00', self::FOOTER, 'PAID', '21 Oct 2026',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('2026-09-01', $html);
        $this->assertSame(1, substr_count($html, 'September 2026'));
        $this->assertStringNotContainsString('VOID', $html);
        $this->assertStringNotContainsString('Added to credit', $html);
    }

    public function test_html_shows_added_to_credit(): void
    {
        $this->bill('2026-09-01', 30);
        $html = $this->pdf()->html($this->issue(40));

        $this->assertStringContainsString('Added to credit', $html);
        $this->assertStringContainsString('40.00', $html);
        $this->assertStringNotContainsString('Less: existing credit applied', $html);
        $this->assertStringNotContainsString('2026-09-01', $html);
        $this->assertSame(1, substr_count($html, 'September 2026'));
    }

    public function test_receipt_issued_under_malay_locale_is_english(): void
    {
        App::setLocale('ms');
        $this->bill('2026-10-01', 30);
        $receipt = $this->issue(30);

        $this->assertSame('Monthly fee: October 2026', $receipt->lines[0]['label']);

        $html = $this->pdf()->html($receipt);
        $this->assertSame('ms', App::getLocale());

        $this->assertStringContainsString('October 2026', $html);
        $this->assertSame(1, substr_count($html, 'October 2026'));
        $this->assertStringNotContainsString('2026-10-01', $html);
        $this->assertStringNotContainsString('Oktober', $html);
        $this->assertStringContainsString('Issued', $html);
        $this->assertStringContainsString('Received from', $html);

        $this->assertStringStartsWith('%PDF', $this->pdf()->render($receipt));
        $this->assertSame('ms', App::getLocale());
    }

    public function test_voided_html(): void
    {
        $this->bill('2026-09-01', 30);
        $receipt = $this->issue(30);

        $this->travelTo(Carbon::parse('2026-10-23 09:00'));
        $receipt->paymentProof->delete();

        $html = $this->pdf()->html($receipt->fresh());

        $this->assertStringContainsString('VOID', $html);
        $this->assertStringContainsString('Voided on 23 Oct 2026', $html);
        $this->assertStringContainsString('This receipt is no longer valid.', $html);
        $this->assertStringNotContainsString('>PAID<', $html);
    }

    public function test_receipt_for_a_deleted_member_still_renders(): void
    {
        $this->bill('2026-09-01', 30);
        $receipt = $this->issue(30);

        $this->member->delete();
        $receipt = $receipt->fresh();

        $this->assertNull($receipt->user_id);
        $this->assertStringStartsWith('%PDF', $this->pdf()->render($receipt));
        $this->assertStringContainsString('Ahmad bin Ismail', $this->pdf()->html($receipt));
    }

    public function test_pdf_is_small(): void
    {
        $this->bill('2026-09-01', 30);

        $this->assertLessThan(100_000, strlen($this->pdf()->render($this->issue(30))));
    }
}
