<?php

namespace App\Http\Resources\Api;

use App\Models\Payment\FeePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeePeriod */
class BillResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // `allocations_sum_amount_allocated` is pre-summed by FeePeriod::forMember().
        $paid = (float) ($this->allocations_sum_amount_allocated ?? 0);
        $outstanding = max(0, (float) $this->amount_due - $paid);

        // Money as fixed-2dp strings — avoids float rounding across the API/client boundary.
        return [
            'id' => $this->id,
            'month' => $this->month_date->format('Y-m'),
            'amount_due' => number_format((float) $this->amount_due, 2, '.', ''),
            'amount_paid' => number_format($paid, 2, '.', ''),
            'outstanding' => number_format($outstanding, 2, '.', ''),
            'status' => $this->status,
        ];
    }
}
