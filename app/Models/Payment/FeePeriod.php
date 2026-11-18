<?php

namespace App\Models\Payment;

use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FeePeriod extends Model
{
    protected $fillable = ['user_id', 'month_date', 'amount_due', 'status', 'type', 'remarks'];

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

        // The status is stored, so an amount edit must re-derive it (and move any
        // now-surplus payment) or the bill keeps judging payments against the old amount.
        static::updated(function (FeePeriod $feePeriod) {
            if ($feePeriod->wasChanged('amount_due')) {
                // A fresh copy: this instance's original isn't synced yet inside `updated`,
                // so saving it again would still see amount_due dirty and loop forever.
                app(FinanceService::class)->reconcileBill($feePeriod->fresh());
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

    // A member's bills, newest first, with the amount paid pre-summed.
    public function scopeForMember(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)
            ->withSum('allocations', 'amount_allocated')
            ->orderByDesc('month_date');
    }
}
