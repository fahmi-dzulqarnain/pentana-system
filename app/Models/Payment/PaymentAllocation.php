<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    protected $fillable = ['payment_proof_id', 'fee_period_id', 'amount_allocated'];

    public function paymentProof()
    {
        return $this->belongsTo(PaymentProof::class);
    }

    public function feePeriod()
    {
        return $this->belongsTo(FeePeriod::class);
    }
}
