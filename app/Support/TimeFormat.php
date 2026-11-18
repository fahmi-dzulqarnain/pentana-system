<?php

namespace App\Support;

use App\Models\User;
use Carbon\Translator;
use Closure;
use Illuminate\Support\Carbon;

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

    private static ?Closure $meridiem = null;

    /**
     * Carbon's Malay and Indonesian locales spell the time of day out ("2:30 petang", "2:30 siang").
     * Members read 12-hour times as "2:30 PM" throughout the portal, so tell Carbon that outright:
     * whether it translates the marker by default has changed between releases (3.15.0).
     */
    public static function keepAmPm(): void
    {
        self::$meridiem ??= static function (int $hour, int $minute = 0, bool $isLower = false): string {
            $marker = $hour < 12 ? 'AM' : 'PM';

            return $isLower ? strtolower($marker) : $marker;
        };

        foreach (array_diff(Locales::SUPPORTED, ['en']) as $locale) {
            // The app-wide translator (app()->setLocale()) and the one behind ->locale($locale).
            foreach ([Carbon::getTranslator(), Translator::get($locale)] as $translator) {
                // Both outlive the application in tests and workers: set it once per translator.
                if ($translator instanceof Translator && ($translator->getMessages($locale)['meridiem'] ?? null) !== self::$meridiem) {
                    $translator->setMessages($locale, ['meridiem' => self::$meridiem]);
                }
            }
        }
    }

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
