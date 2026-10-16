<?php

namespace App\Support;

use App\Models\User;

/**
 * 12- or 24-hour clock. A member can choose; otherwise it follows their language:
 * 24-hour is the norm in Indonesian, 12-hour in English and Malay.
 *
 * Whose preference applies: the member a notification is being rendered for (set around
 * each delivery from the notification events), otherwise the signed-in user.
 */
class TimeFormat
{
    public const TWELVE = '12h';

    public const TWENTY_FOUR = '24h';

    private static ?User $recipient = null;

    /** @return list<string> */
    public static function choices(): array
    {
        return [self::TWELVE, self::TWENTY_FOUR];
    }

    public static function useRecipient(?User $user): void
    {
        self::$recipient = $user;
    }

    /** The clock for this user (or whoever applies right now), in the current language. */
    public static function resolve(?User $user = null): string
    {
        $user ??= self::$recipient ?? auth()->user();

        if (in_array($user?->time_format, self::choices(), true)) {
            return $user->time_format;
        }

        return app()->getLocale() === 'id' ? self::TWENTY_FOUR : self::TWELVE;
    }

    /** Carbon format for the time of day: "2:30 PM" or "14:30". */
    public static function time(?User $user = null): string
    {
        return self::resolve($user) === self::TWENTY_FOUR ? 'H:i' : 'g:i A';
    }
}
