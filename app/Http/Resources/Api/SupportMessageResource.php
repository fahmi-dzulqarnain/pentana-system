<?php

namespace App\Http\Resources\Api;

use App\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message for the member's app: whose side it is on, never who wrote it, so an admin's
 * name or id never reaches the device.
 *
 * @mixin SupportMessage
 */
class SupportMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'from_committee' => $this->from_committee,
            'is_mine' => $this->user_id === $request->user()?->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'attachment_url' => $this->attachment_path ? route('api.support-tickets.attachment', $this->resource) : null,
        ];
    }
}
