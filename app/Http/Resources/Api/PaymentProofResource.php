<?php

namespace App\Http\Resources\Api;

use App\Models\Payment\PaymentProof;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin PaymentProof */
class PaymentProofResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount_claimed' => number_format((float) $this->amount_claimed, 2, '.', ''),
            'amount_verified' => $this->amount_verified !== null
                ? number_format((float) $this->amount_verified, 2, '.', '')
                : null,
            'status' => $this->status,
            'member_note' => $this->member_note,
            'admin_note' => $this->admin_note,
            'proof_image_url' => $this->proof_image_path
                ? Storage::disk('public')->url($this->proof_image_path)
                : null,
            'receipt_number' => $this->receipt?->number,
            'has_receipt' => $this->receipt !== null,
            'submitted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
