<?php

namespace App\Models\Payment;

use App\Enums\StatementLineOutcome;
use App\Models\User;
use Database\Factories\BankStatementLineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class BankStatementLine extends Model
{
    use HasFactory;

    protected static function newFactory(): BankStatementLineFactory
    {
        return BankStatementLineFactory::new();
    }

    protected $fillable = [
        'bank_statement_import_id', 'txn_date', 'type', 'bank_ref_no', 'amount', 'occurrence',
        'reference_text', 'user_id', 'payment_proof_id', 'outcome',
    ];

    protected function casts(): array
    {
        return ['txn_date' => 'date', 'occurrence' => 'integer', 'outcome' => StatementLineOutcome::class];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentProof(): BelongsTo
    {
        return $this->belongsTo(PaymentProof::class);
    }

    /** Credits not yet tied to a payment proof. */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('payment_proof_id');
    }

    /**
     * Unmatched lines carrying this proof's member code that the matcher left for a human
     * (wrong amount, no proof at the time): a hint for the reviewing admin, newest first.
     *
     * @return Collection<int, static>
     */
    public static function possibleMatchesFor(PaymentProof $proof): Collection
    {
        return static::query()
            ->unmatched()
            ->where('user_id', $proof->user_id)
            ->whereIn('outcome', [StatementLineOutcome::NeedsReview, StatementLineOutcome::NoProof])
            ->whereDate('txn_date', '>=', $proof->created_at->copy()->subDays(30)->toDateString())
            ->orderByDesc('txn_date')
            ->orderByDesc('id')
            ->get();
    }
}
