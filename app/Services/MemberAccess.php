<?php

namespace App\Services;

use App\Models\User;

/** Admin-driven disable / enable, e.g. when a member resigns and later rejoins. */
class MemberAccess
{
    /** Admins can't be disabled here, so nobody locks the last admin out. */
    public function canDisable(User $user): bool
    {
        return ! $user->is_admin && ! $user->isManuallyDisabled();
    }

    public function disable(User $user): void
    {
        // The nickname goes too, so a departed member's name frees up for someone else.
        $user->forceFill(['disabled_at' => now(), 'nickname' => null])->save();

        // Sign them out of the mobile app and stop pushes. The web session ends on its
        // next request, because canAccessPanel() refuses disabled members.
        $user->tokens()->delete();
        $user->deviceTokens()->delete();
    }

    public function enable(User $user): void
    {
        $user->forceFill(['disabled_at' => null])->save();
    }
}
