<?php

namespace App\Http\Resources\Api;

use App\Models\SupportTicket;
use Illuminate\Http\Request;

/**
 * A ticket with its conversation. Who closed it is reduced to "by the committee or not":
 * the member's app never learns which admin.
 *
 * @mixin SupportTicket
 */
class SupportTicketDetailResource extends SupportTicketResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by_committee' => (bool) $this->closedBy?->is_admin,
            'messages' => SupportMessageResource::collection($this->messages),
        ];
    }
}
