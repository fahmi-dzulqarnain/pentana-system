<?php

namespace App\Models\Payment;

use App\Enums\StatementLineOutcome;
use App\Models\User;
use Database\Factories\BankStatementLineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementLine extends Model
{
    use HasFactory;

    protected static function newFactory(): BankStatementLineFactory
    {
        return BankStatementLineFactory::new();
    }

    protected $fillable = [
        'bank_statement_import_id', 'txn_date', 'type', 'bank_ref_no', 'amount',
        'reference_text', 'user_id', 'payment_proof_id', 'outcome',
    ];

    protected function casts(): array
    {
        return ['txn_date' => 'date', 'outcome' => StatementLineOutcome::class];
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
}
