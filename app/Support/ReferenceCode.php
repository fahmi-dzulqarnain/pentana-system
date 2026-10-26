<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Per-member payment reference: "PTN" + user id zero-padded to 4 digits + a Luhn check
 * digit (user 142 -> PTN01420). Derived from the id, never stored. The check digit lets
 * the statement matcher reject mistyped references instead of crediting the wrong member.
 */
class ReferenceCode
{
    public const PREFIX = 'PTN';

    public const MAX_USER_ID = 9999;

    public static function for(int $userId): string
    {
        if ($userId < 0 || $userId > self::MAX_USER_ID) {
            throw new InvalidArgumentException("User id {$userId} cannot be encoded as a reference code.");
        }

        $payload = str_pad((string) $userId, 4, '0', STR_PAD_LEFT);

        return self::PREFIX.$payload.self::checkDigit($payload);
    }

    public static function isValid(string $code): bool
    {
        return preg_match('/^'.self::PREFIX.'(\d{4})(\d)$/i', $code, $m) === 1
            && self::checkDigit($m[1]) === (int) $m[2];
    }

    /** The one member id whose valid code appears in the text; null for none, invalid, or several. */
    public static function extractUserId(string $text): ?int
    {
        preg_match_all('/'.self::PREFIX.'\s*((?:\d\s*){4}\d)(?!\d)/i', $text, $matches);

        $ids = [];
        foreach ($matches[1] as $capture) {
            $digits = preg_replace('/\s+/', '', $capture);
            if (self::isValid(self::PREFIX.$digits)) {
                $ids[(int) substr($digits, 0, 4)] = true;
            }
        }

        return count($ids) === 1 ? array_key_first($ids) : null;
    }

    /** Luhn check digit for a 4-digit payload: double every second digit from the right. */
    private static function checkDigit(string $payload): int
    {
        $sum = 0;
        foreach (array_reverse(str_split($payload)) as $i => $digit) {
            $d = (int) $digit;
            if ($i % 2 === 0) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return (10 - $sum % 10) % 10;
    }
}
