<?php

namespace App\Models\Payment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class FeePeriod extends Model
{
    protected $fillable = ['user_id', 'month_date', 'amount_due', 'status'];
    protected $casts = [
        'month_date' => 'date',
        'amount_due' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::saving(function ($feePeriod) {
            if ($feePeriod->amount_due <= 0) {
                $feePeriod->status = 'paid';
                $feePeriod->type = 'free';
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Get all payments that have contributed to paying off this month
    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
