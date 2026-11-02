<?php

namespace App\Services\BankStatements;

use Illuminate\Support\Carbon;

final readonly class StatementCredit
{
    /**
     * @param  string  $amount  Two decimals, no thousands separator, e.g. '1050.00'.
     */
    public function __construct(
        public Carbon $date,
        public string $type,
        public string $bankRefNo,
        public string $amount,
        public string $referenceText,
    ) {}
}
