<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\MemberInvitation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Claiming proves you own the inbox: the only way in is the signed link we email.
 * Knowing someone's address (or birthday) is not enough to activate their account.
 */
class AccountClaimService
{
    /** One link per address per this many seconds, so a public form can't flood an inbox or the email budget. */
    public const LINK_DECAY_SECONDS = 600;

    /** The unclaimed user for this email, or null if unknown, already activated or disabled. */
    public function claimable(string $email): ?User
    {
        return User::where('email', trim($email))->whereNull('activated_at')->enabled()->first();
    }

    /**
     * The member's personal claim link, valid until their invitation expires. It is bound to
     * invited_at, so resending the invitation retires every earlier link.
     */
    public function link(User $user): string
    {
        return URL::temporarySignedRoute(
            'claim.accept',
            $user->invitationExpiresAt() ?? now()->addDays(User::INVITATION_VALID_DAYS),
            ['user' => $user->id, 'invited' => $user->invited_at?->getTimestamp() ?? 0],
        );
    }

    /** The member a signature-checked link points at, or null once it is claimed, disabled or superseded. */
    public function userForLink(int $userId, int $invitedAt): ?User
    {
        $user = User::whereKey($userId)->whereNull('activated_at')->enabled()->first();

        return $user?->invited_at?->getTimestamp() === $invitedAt ? $user : null;
    }

    /**
     * Emails the claim link to whoever owns $email, if that account is waiting to be claimed;
     * otherwise (or when one went out in the last few minutes) does nothing, so callers can
     * answer the same way either way. A member who was never invited gets a fresh invitation
     * (starting their 30-day window); one already invited gets the same invitation again,
     * without extending it.
     */
    public function sendLink(string $email): void
    {
        $user = $this->claimable($email);
        $key = 'claim-link:'.sha1(mb_strtolower(trim($email)));

        if ($user === null || RateLimiter::tooManyAttempts($key, 1)) {
            return;
        }

        RateLimiter::hit($key, self::LINK_DECAY_SECONDS);

        if ($user->invited_at === null) {
            app(MemberInviter::class)->invite($user);

            return;
        }

        $user->notify(new MemberInvitation);
    }

    /** @throws RuntimeException if already activated. */
    public function claim(User $user, string $birthday, string $password): User
    {
        if ($user->activated_at !== null) {
            throw new RuntimeException('This account has already been activated.');
        }

        $user->forceFill([
            'birthday' => $birthday,
            'password' => $password, // hashed by the model cast
            'activated_at' => now(),
        ])->save();

        return $user;
    }
}
