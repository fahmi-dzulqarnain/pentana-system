<?php

namespace App\Http\Resources\Api;

use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket as the member's app lists it. Status is the raw value; the app words it.
 *
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'subject' => $this->subject,
            'status' => $this->status->value,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_from_committee' => (bool) $this->latestMessage?->from_committee,
        ];
    }
}
