<?php

namespace App\Services\BankStatements;

use App\Enums\StatementLineOutcome;
use App\Models\Payment\BankStatementImport;
use App\Models\Payment\BankStatementLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reads an uploaded bank statement, stores its credits once, and matches every credit
 * still unlinked (this statement's and earlier ones'). The PDF is always deleted.
 */
class StatementImporter
{
    public function __construct(
        private StatementTextExtractor $extractor,
        private CimbStatementParser $parser,
        private StatementMatcher $matcher,
    ) {}

    /**
     * @throws StatementParseException
     * @throws DuplicateStatementException
     */
    public function import(string $pdfPath, User $uploadedBy): BankStatementImport
    {
        try {
            $hash = hash_file('sha256', $pdfPath);

            if (BankStatementImport::where('file_sha256', $hash)->exists()) {
                throw new DuplicateStatementException(__('This statement has already been imported.'));
            }

            $statement = $this->parser->parse($this->extractor->extract($pdfPath));

            try {
                $import = DB::transaction(fn () => $this->store($statement, $hash, $uploadedBy));
            } catch (UniqueConstraintViolationException) {
                // Lines use insertOrIgnore, so only the import's file hash can collide: a concurrent upload of this file won.
                throw new DuplicateStatementException(__('This statement has already been imported.'));
            }
        } finally {
            @unlink($pdfPath);
        }

        // Outside the store transaction: each auto-verify commits on its own and member
        // notifications are deferred, so a rollback here must not be able to undo them.
        $result = $this->matcher->match(BankStatementLine::unmatched()->orderBy('txn_date')->orderBy('id')->get());

        $import->update([
            'auto_verified' => $result->autoVerified,
            'confirmed' => $result->confirmed,
            'unmatched' => $result->unmatched,
        ]);

        return $import;
    }

    private function store(ParsedStatement $statement, string $hash, User $uploadedBy): BankStatementImport
    {
        $import = BankStatementImport::create([
            'uploaded_by' => $uploadedBy->id,
            'file_sha256' => $hash,
            'statement_from' => $statement->from,
            'statement_to' => $statement->to,
        ]);

        $new = 0;
        foreach ($statement->credits as $credit) {
            $new += BankStatementLine::insertOrIgnore([
                'bank_statement_import_id' => $import->id,
                'txn_date' => $credit->date->toDateString(),
                'type' => $credit->type,
                'bank_ref_no' => $credit->bankRefNo,
                'amount' => $credit->amount,
                'reference_text' => $credit->referenceText,
                'user_id' => null,
                'payment_proof_id' => null,
                'outcome' => StatementLineOutcome::NeedsReview->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $import->update(['lines_new' => $new, 'lines_duplicate' => count($statement->credits) - $new]);

        return $import;
    }
}
