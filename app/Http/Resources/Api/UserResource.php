<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nickname' => $this->nickname,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'member_category' => $this->memberCategory?->name,
            'birthday' => $this->birthday?->toDateString(),
            'credit' => (float) $this->credit,
            // Lets the apps hide member-only features (Ask the committee) from admins.
            'is_admin' => (bool) $this->is_admin,
            'payment_reference' => $this->payment_reference,
        ];
    }
}
