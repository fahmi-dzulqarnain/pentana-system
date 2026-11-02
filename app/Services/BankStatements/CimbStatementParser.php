<?php

namespace App\Services\BankStatements;

use App\Services\FinanceService;
use Illuminate\Support\Carbon;

/**
 * Reads the `pdftotext -layout` text of a CIMB BizChannel account statement.
 *
 * Rows are listed newest first and each balance is the balance after that row. The
 * withdrawal/deposit columns are not told apart by position: every row is classified
 * by walking the balances from the opening balance, and the result must agree with the
 * statement's own summary. Anything that does not reconcile is refused outright, so a
 * misread row can never reach the matcher.
 */
class CimbStatementParser
{
    private const AMOUNT = '-?[\d,]+\.\d{2}';

    public function parse(string $text): ParsedStatement
    {
        if (! str_contains($text, 'Statement of Account') || ! str_contains($text, 'CIMB')) {
            throw $this->notCimb();
        }

        $rows = [];
        $opening = null;
        $summary = null;
        $current = null;        // index of the row that continuation lines belong to
        $inPageHeader = true;   // everything above a page's "(RM)" header line is ignored
        $inSummary = false;

        foreach (preg_split('/\R/', $text) as $line) {
            if (str_contains($line, "\f")) {
                $inPageHeader = true;
                $current = null;

                continue;
            }

            if ($inPageHeader) {
                if (str_contains($line, '(RM)')) {
                    $inPageHeader = false;
                    // A page break can split a transaction: its remaining lines open the next page.
                    $current = array_key_last($rows);
                }

                continue;
            }

            if ($inSummary) {
                if (preg_match('/^\s*(\d+)\s+(\d+)\s+('.self::AMOUNT.')\s+('.self::AMOUNT.')\s*$/', $line, $m)) {
                    $summary = [
                        'debitCount' => (int) $m[1],
                        'creditCount' => (int) $m[2],
                        'debitTotal' => $this->cents($m[3]),
                        'creditTotal' => $this->cents($m[4]),
                    ];
                }

                continue;
            }

            if (str_contains($line, 'No of Withdrawal')) {
                $inSummary = true;
                $current = null;

                continue;
            }

            if (preg_match('/Opening Balance\s+('.self::AMOUNT.')/', $line, $m)) {
                $opening = $this->cents($m[1]);
                $current = null;

                continue;
            }

            if (preg_match('/^\d{2}\/\d{2}\/\d{4}\s/', $line)) {
                $rows[] = $this->row($line);
                $current = array_key_last($rows);

                continue;
            }

            if (trim($line) === '') {
                $current = null;

                continue;
            }

            if ($current !== null) {
                foreach ($this->tokens($line) as [$token, $column]) {
                    if ($column >= $rows[$current]['refColumn']) {
                        $rows[$current]['refNo'] .= $token;
                    } else {
                        $rows[$current]['text'][] = $token;
                    }
                }
            }
        }

        if ($opening === null || $summary === null) {
            throw $this->notCimb();
        }

        if ($rows === []) {
            throw new StatementParseException(__('No transactions found'));
        }

        return new ParsedStatement(
            from: end($rows)['date'],
            to: $rows[0]['date'],
            credits: $this->reconcile($rows, $opening, $summary),
        );
    }

    /**
     * Classify every row as credit or debit by its balance, check the statement's totals,
     * and return the credits in statement order.
     *
     * @return list<StatementCredit>
     */
    private function reconcile(array $rows, int $opening, array $summary): array
    {
        $balance = $opening;
        $tally = ['debitCount' => 0, 'creditCount' => 0, 'debitTotal' => 0, 'creditTotal' => 0];
        $credits = [];

        foreach (array_reverse($rows) as $row) {
            if ($balance + $row['amount'] === $row['balance']) {
                $tally['creditCount']++;
                $tally['creditTotal'] += $row['amount'];
                $credits[] = new StatementCredit(
                    date: $row['date'],
                    type: $row['type'],
                    bankRefNo: $row['refNo'],
                    amount: sprintf('%d.%02d', intdiv($row['amount'], 100), $row['amount'] % 100),
                    referenceText: implode(' ', $row['text']),
                );
            } elseif ($balance - $row['amount'] === $row['balance']) {
                $tally['debitCount']++;
                $tally['debitTotal'] += $row['amount'];
            } else {
                throw new StatementParseException(__('Balance does not reconcile on :date', [
                    'date' => $row['date']->format('d/m/Y'),
                ]));
            }

            $balance = $row['balance'];
        }

        if ($tally !== $summary) {
            throw new StatementParseException(__('The statement totals do not match its transactions.'));
        }

        return array_reverse($credits);
    }

    /** Split a transaction row into date, type, ref no, amount and balance. */
    private function row(string $line): array
    {
        $tokens = $this->tokens($line);
        $date = Carbon::createFromFormat('d/m/Y', $tokens[0][0])->startOfDay();
        $amounts = array_slice($tokens, -2);
        $middle = array_slice($tokens, 1, -2);

        if (count($middle) < 1 || count($middle) > 2
            || ! preg_match('/^'.self::AMOUNT.'$/', $amounts[0][0])
            || ! preg_match('/^'.self::AMOUNT.'$/', $amounts[1][0])) {
            throw new StatementParseException(__('Could not read the transaction on :date.', [
                'date' => $date->format('d/m/Y'),
            ]));
        }

        return [
            'date' => $date,
            'type' => $middle[0][0],
            'refNo' => $middle[1][0] ?? '',
            // Without a ref no on the row, nothing below it can be ref-no continuation.
            'refColumn' => $middle[1][1] ?? PHP_INT_MAX,
            'amount' => $this->cents($amounts[0][0]),
            'balance' => $this->cents($amounts[1][0]),
            'text' => [],
        ];
    }

    /**
     * Cells of a layout line: runs of words separated by single spaces, with their column.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function tokens(string $line): array
    {
        preg_match_all('/\S+(?: \S+)*/', $line, $m, PREG_OFFSET_CAPTURE);

        return $m[0];
    }

    private function cents(string $amount): int
    {
        return FinanceService::toCents(str_replace(',', '', $amount));
    }

    private function notCimb(): StatementParseException
    {
        return new StatementParseException(__('Not a CIMB BizChannel statement'));
    }
}
