<?php

namespace App\Models\Payment;

use App\Models\User;
use App\Services\FinanceService;
use App\Services\ReceiptService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class PaymentProof extends Model
{
    protected $fillable = [
        'user_id', 'proof_image_path', 'member_note', 'amount_claimed',
        'amount_verified', 'status', 'admin_note', 'verified_at',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Covers every delete path (admin action, bulk delete, code), not just one page.
        static::deleting(function (PaymentProof $payment) {
            app(ReceiptService::class)->voidFor($payment);
            app(FinanceService::class)->releasePayment($payment);
        });
    }

    /**
     * One transaction around the deleting hook and the row delete, so a failure in
     * releasePayment() (or the delete itself) also rolls back the receipt's void.
     */
    public function delete()
    {
        return DB::transaction(fn () => parent::delete());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Get the breakdown of where this money went
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class);
    }

    // Helper to see which months this proof paid for
    public function paidPeriods()
    {
        return $this->hasManyThrough(
            FeePeriod::class,
            PaymentAllocation::class,
            'payment_proof_id', // Foreign key on allocations table
            'id', // Foreign key on fee_periods table
            'id', // Local key on payment_proofs table
            'fee_period_id' // Local key on allocations table
        );
    }
}
