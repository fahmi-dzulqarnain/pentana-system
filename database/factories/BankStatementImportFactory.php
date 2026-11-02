<?php

namespace Database\Factories;

use App\Models\Payment\BankStatementImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankStatementImport>
 */
class BankStatementImportFactory extends Factory
{
    protected $model = BankStatementImport::class;

    public function definition(): array
    {
        return [
            'uploaded_by' => User::factory(),
            'file_sha256' => hash('sha256', fake()->unique()->uuid()),
            'statement_from' => '2026-10-01',
            'statement_to' => '2026-10-31',
        ];
    }
}
