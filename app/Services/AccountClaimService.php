<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class AccountClaimService
{
    /** The unclaimed user for this email, or null if unknown, already activated or disabled. */
    public function claimable(string $email): ?User
    {
        return User::where('email', trim($email))->whereNull('activated_at')->enabled()->first();
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
