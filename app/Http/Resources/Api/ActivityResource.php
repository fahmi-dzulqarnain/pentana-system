<?php

namespace App\Http\Resources\Api;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $userId = $request->user()->id;

        // `registrations` is eager-loaded (all of them, for counts + the caller's status).
        $mine = $this->registrations->first(
            fn ($registration) => $registration->user_id === $userId && $registration->status !== 'cancelled'
        );
        $status = $mine?->status ?? 'none';

        $waitlistPosition = null;
        if ($status === 'waitlisted') {
            $waitlisted = $this->registrations
                ->where('status', 'waitlisted')
                ->sortBy([['registered_at', 'asc'], ['id', 'asc']])
                ->values();
            $index = $waitlisted->search(fn ($registration) => $registration->user_id === $userId);
            $waitlistPosition = $index === false ? null : $index + 1;
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'location' => $this->location,
            'spots_left' => $this->spotsLeft(),
            'is_open' => $this->isOpenForRegistration(),
            'my_status' => $status,
            'waitlist_position' => $waitlistPosition,
            'questions' => $this->registration_fields ?? [],
        ];
    }
}
