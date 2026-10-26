<?php

namespace App\Services\BankStatements;

use Illuminate\Support\Carbon;

final readonly class ParsedStatement
{
    /**
     * @param  list<StatementCredit>  $credits  In statement order (newest first).
     */
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public array $credits,
    ) {}
}
