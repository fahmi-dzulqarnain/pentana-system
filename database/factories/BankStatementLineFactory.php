<?php

namespace Database\Factories;

use App\Enums\StatementLineOutcome;
use App\Models\Payment\BankStatementImport;
use App\Models\Payment\BankStatementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankStatementLine>
 */
class BankStatementLineFactory extends Factory
{
    protected $model = BankStatementLine::class;

    public function definition(): array
    {
        return [
            'bank_statement_import_id' => BankStatementImport::factory(),
            'txn_date' => '2026-10-05',
            'type' => 'IBG CREDIT',
            'bank_ref_no' => fake()->unique()->numerify('REF##########'),
            'amount' => '30.00',
            'reference_text' => fake()->sentence(3),
            'outcome' => StatementLineOutcome::NoCode,
        ];
    }
}
