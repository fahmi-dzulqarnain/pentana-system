<?php

namespace App\Support;

/**
 * The languages PENTANA can render, and which of them are switched on.
 * APP_LOCALES gates rollout: a language is only offered once it is listed there.
 */
class Locales
{
    public const SUPPORTED = ['en', 'ms', 'id'];

    private const NATIVE_NAMES = ['en' => 'English', 'ms' => 'Bahasa Melayu', 'id' => 'Bahasa Indonesia'];

    private const SHORT_LABELS = ['en' => 'EN', 'ms' => 'BM', 'id' => 'ID'];

    /** @return list<string> */
    public static function enabled(): array
    {
        $configured = array_map('trim', explode(',', (string) config('app.locales', 'en')));
        $known = array_values(array_intersect($configured, self::SUPPORTED));

        return array_values(array_unique(['en', ...array_diff($known, ['en'])]));
    }

    public static function isEnabled(?string $code): bool
    {
        return $code !== null && $code !== '' && in_array($code, self::enabled(), true);
    }

    public static function multipleEnabled(): bool
    {
        return count(self::enabled()) > 1;
    }

    public static function nativeName(string $code): string
    {
        return self::NATIVE_NAMES[$code];
    }

    public static function shortLabel(string $code): string
    {
        return self::SHORT_LABELS[$code];
    }
}
