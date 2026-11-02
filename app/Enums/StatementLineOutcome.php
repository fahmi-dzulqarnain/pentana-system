<?php

namespace App\Enums;

/**
 * What became of one bank-statement credit: matched and verified by the import,
 * confirmed against an already-verified proof, or left for the treasurer.
 */
enum StatementLineOutcome: string
{
    case AutoVerified = 'auto_verified';
    case Confirmed = 'confirmed';
    case NeedsReview = 'needs_review';
    case NoProof = 'no_proof';
    case NoCode = 'no_code';

    public function label(): string
    {
        return match ($this) {
            self::AutoVerified => __('Auto-verified'),
            self::Confirmed => __('Confirmed'),
            self::NeedsReview => __('Needs review'),
            self::NoProof => __('No proof submitted'),
            self::NoCode => __('No reference code'),
        };
    }
}
