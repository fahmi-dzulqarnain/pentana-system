<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\MemberInvitation;

class MemberInviter
{
    /** Only members who still have to claim their account can be invited. */
    public function canInvite(User $user): bool
    {
        return ! $user->is_admin && $user->activated_at === null;
    }

    /**
     * Sends the invitation inline (there is no queue worker) and restarts the 30-day window,
     * which also re-enables a disabled account. False if the member is not invitable.
     */
    public function invite(User $user): bool
    {
        if (! $this->canInvite($user)) {
            return false;
        }

        // Stamp first: the email quotes the expiry date derived from invited_at.
        $user->forceFill(['invited_at' => now()])->save();
        $user->notify(new MemberInvitation);

        return true;
    }
}
