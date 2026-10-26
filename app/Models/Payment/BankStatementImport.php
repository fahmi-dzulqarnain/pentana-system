<?php

namespace App\Models\Payment;

use App\Enums\StatementLineOutcome;
use App\Models\User;
use App\Services\BankStatements\MatchResult;
use Database\Factories\BankStatementImportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImport extends Model
{
    use HasFactory;

    protected static function newFactory(): BankStatementImportFactory
    {
        return BankStatementImportFactory::new();
    }

    protected $fillable = [
        'uploaded_by', 'file_sha256', 'statement_from', 'statement_to',
        'lines_new', 'lines_duplicate',
    ];

    /**
     * Not stored: what the matching run of this upload did, set by StatementImporter for the
     * success message. It may include earlier imports' lines that this run resolved.
     */
    public ?MatchResult $matchResult = null;

    protected function casts(): array
    {
        return ['statement_from' => 'date', 'statement_to' => 'date'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    /**
     * Adds auto_verified_count, confirmed_count and to_review_count, counted live from this
     * import's own lines in the same query, so they follow lines resolved by later imports.
     */
    public function scopeWithOutcomeCounts(Builder $query): Builder
    {
        $resolved = [StatementLineOutcome::AutoVerified->value, StatementLineOutcome::Confirmed->value];

        return $query->withCount([
            'lines as auto_verified_count' => fn (Builder $q) => $q->where('outcome', StatementLineOutcome::AutoVerified->value),
            'lines as confirmed_count' => fn (Builder $q) => $q->where('outcome', StatementLineOutcome::Confirmed->value),
            'lines as to_review_count' => fn (Builder $q) => $q->whereNotIn('outcome', $resolved),
        ]);
    }
}
