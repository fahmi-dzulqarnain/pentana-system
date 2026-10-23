<?php

namespace App\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/** The live meter under a password field; see resources/views/components/password-strength.blade.php. */
class PasswordStrengthMeter
{
    /** Words every member knows, so zxcvbn treats them like dictionary words ("Pentana2026!"). */
    private const CLUB_WORDS = ['pentana', 'silentmode'];

    /** @param  list<?string>  $userInputs  The member's own details, which make for easy guesses. */
    public static function make(array $userInputs = []): Htmlable
    {
        return new HtmlString(Blade::render('<x-password-strength :user-inputs="$userInputs" />', [
            'userInputs' => [...self::CLUB_WORDS, ...self::words($userInputs)],
        ]));
    }

    /**
     * zxcvbn only matches whole inputs, so "Aisyah Rahman" alone wouldn't catch "aisyahrahman95":
     * also hand it each name part and the email's local part.
     *
     * @param  list<?string>  $inputs
     * @return list<string>
     */
    private static function words(array $inputs): array
    {
        return collect($inputs)
            ->filter()
            ->flatMap(fn (string $input): array => [
                $input,
                Str::before($input, '@'),
                ...preg_split('/[^\pL\pN]+/u', Str::before($input, '@'), flags: PREG_SPLIT_NO_EMPTY),
            ])
            ->map(fn (string $word): string => Str::lower($word))
            ->filter(fn (string $word): bool => mb_strlen($word) >= 3)
            ->unique()
            ->values()
            ->all();
    }
}
