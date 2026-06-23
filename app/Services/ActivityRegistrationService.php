<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ActivityRegistrationService
{
    /**
     * Register a member for an activity, taking a free seat or joining the waitlist.
     *
     * @throws RegistrationException when registration is not open.
     */
    public function register(Activity $activity, User $user): ActivityRegistration
    {
        if (! $activity->isOpenForRegistration()) {
            throw new RegistrationException('Registration is closed for this activity.');
        }

        return DB::transaction(function () use ($activity, $user) {
            // Lock the activity row so two members can't both claim the final seat.
            $locked = Activity::whereKey($activity->getKey())->lockForUpdate()->first();

            $existing = ActivityRegistration::where('activity_id', $locked->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing && in_array($existing->status, ['registered', 'waitlisted'], true)) {
                return $existing; // already in — idempotent
            }

            return ActivityRegistration::updateOrCreate(
                ['activity_id' => $locked->id, 'user_id' => $user->id],
                [
                    'status' => $locked->isFull() ? 'waitlisted' : 'registered',
                    'registered_at' => now(),
                ],
            );
        });
    }

    /** Cancel a member's registration, promoting the next waitlisted member if a seat frees. */
    public function cancel(Activity $activity, User $user): void
    {
        DB::transaction(function () use ($activity, $user) {
            $locked = Activity::whereKey($activity->getKey())->lockForUpdate()->first();

            $registration = ActivityRegistration::where('activity_id', $locked->id)
                ->where('user_id', $user->id)
                ->first();

            if ($registration === null || $registration->status === 'cancelled') {
                return;
            }

            $freedSeat = $registration->status === 'registered';
            $registration->update(['status' => 'cancelled']);

            if ($freedSeat) {
                $this->promoteWaitlist($locked);
            }
        });
    }

    /** Promote waitlisted members into any open seats (e.g. after a capacity increase). */
    public function reconcileWaitlist(Activity $activity): void
    {
        DB::transaction(function () use ($activity) {
            $locked = Activity::whereKey($activity->getKey())->lockForUpdate()->first();
            $this->promoteWaitlist($locked);
        });
    }

    /**
     * Fill open seats from the waitlist, earliest first. The caller must hold the activity lock.
     * (ACT-3 wires the WaitlistPromoted notification at the marked seam.)
     */
    private function promoteWaitlist(Activity $activity): void
    {
        if ($activity->isPast()) {
            return;
        }

        while (true) {
            $registered = $activity->registrations()->where('status', 'registered')->count();

            if ($activity->capacity !== null && $registered >= $activity->capacity) {
                break;
            }

            $next = $activity->registrations()
                ->where('status', 'waitlisted')
                ->orderBy('registered_at')
                ->orderBy('id')
                ->first();

            if ($next === null) {
                break;
            }

            $next->update(['status' => 'registered']);

            // ACT-3 seam: notify the promoted member — $next->user->notify(new WaitlistPromoted($activity));
        }
    }
}
