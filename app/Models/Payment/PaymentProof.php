<?php

namespace App\Models\Payment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentProof extends Model
{
    protected $fillable = [
        'user_id', 'proof_image_path', 'member_note', 'amount_claimed',
        'amount_verified', 'status', 'admin_note',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Get the breakdown of where this money went
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
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
