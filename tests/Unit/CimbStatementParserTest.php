<?php

namespace Tests\Unit;

use App\Services\BankStatements\CimbStatementParser;
use App\Services\BankStatements\PdftotextExtractor;
use App\Services\BankStatements\StatementParseException;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CimbStatementParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/bank-statements/cimb-synthetic.txt'));
    }

    public function test_parses_only_credits_with_reference_text(): void
    {
        $s = (new CimbStatementParser)->parse($this->fixture());

        $this->assertSame('2024-09-01', $s->from->toDateString());
        $this->assertSame('2024-09-28', $s->to->toDateString());
        $this->assertSame(
            ['1050.00', '15.00', '15.00', '15.00', '105.00'],
            array_map(fn ($c) => $c->amount, $s->credits),
        );
        $ibg = $s->credits[2];
        $this->assertSame('IBG CREDIT', $ibg->type);
        $this->assertSame('2001000000000000186', $ibg->bankRefNo);
        $this->assertSame('CLUB FEE ptn 0142 8 sept', $ibg->referenceText);
        $this->assertSame('PTN01428 Yuran Jul-Jan', $s->credits[4]->referenceText);
    }

    public function test_continuation_after_a_page_break_stays_with_its_row(): void
    {
        // TR IBG "Caterer" continues as "September payment" on page 2; it is a debit, so
        // it is dropped — but the first page-2 credit must not inherit that text.
        $s = (new CimbStatementParser)->parse($this->fixture());
        $this->assertSame('PENTANA lunch September', $s->credits[1]->referenceText);
    }

    public function test_refuses_a_statement_whose_balances_do_not_reconcile(): void
    {
        $broken = str_replace('105.00         1,105.00', '150.00         1,105.00', $this->fixture());
        $this->expectException(StatementParseException::class);
        (new CimbStatementParser)->parse($broken);
    }

    public function test_refuses_a_dropped_row(): void
    {
        $dropped = preg_replace('/^03\/09\/2024.*\n.*Fund Transfer\n/m', '', $this->fixture());
        $this->expectException(StatementParseException::class);
        (new CimbStatementParser)->parse($dropped);
    }

    public function test_refuses_text_that_is_not_a_cimb_statement(): void
    {
        $this->expectException(StatementParseException::class);
        (new CimbStatementParser)->parse("Some other bank\n01/09/2024  X  1  2.00  3.00\n");
    }

    public function test_extractor_refuses_a_pdf_without_text(): void
    {
        Process::fake(['*' => Process::result('')]);

        $this->expectException(StatementParseException::class);
        (new PdftotextExtractor)->extract('/tmp/scan.pdf');
    }
}
