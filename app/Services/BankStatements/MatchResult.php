<?php

namespace App\Services\BankStatements;

/** What one StatementMatcher run did with its lines. */
final readonly class MatchResult
{
    /** @param  list<int>  $verifiedProofIds */
    public function __construct(
        public int $autoVerified,
        public int $confirmed,
        public int $unmatched,
        public array $verifiedProofIds,
    ) {}
}
