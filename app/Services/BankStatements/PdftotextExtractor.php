<?php

namespace App\Services\BankStatements;

use Illuminate\Support\Facades\Process;

/** Extracts statement text with poppler's `pdftotext -layout`, which keeps columns aligned. */
class PdftotextExtractor implements StatementTextExtractor
{
    public function extract(string $pdfPath): string
    {
        $result = Process::run(['pdftotext', '-layout', $pdfPath, '-']);

        if ($result->failed() || trim($result->output()) === '') {
            throw new StatementParseException(__('This PDF has no readable text. Upload the statement downloaded from BizChannel, not a scan.'));
        }

        return $result->output();
    }
}
