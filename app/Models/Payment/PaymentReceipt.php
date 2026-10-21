<?php

namespace App\Models\Payment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReceipt extends Model
{
    protected $fillable = [
        'number', 'year', 'sequence', 'user_id', 'payment_proof_id',
        'member_name', 'member_email', 'amount_received', 'credit_used',
        'credit_added', 'lines', 'verified_at', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'verified_at' => 'datetime',
            'voided_at' => 'datetime',
            'amount_received' => 'decimal:2',
            'credit_used' => 'decimal:2',
            'credit_added' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentProof(): BelongsTo
    {
        return $this->belongsTo(PaymentProof::class);
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    public function filename(): string
    {
        return "{$this->number}.pdf";
    }
}
