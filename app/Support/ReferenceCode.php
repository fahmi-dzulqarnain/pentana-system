<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Per-member payment reference: "PTN" + user id zero-padded to 4 digits + a Damm check
 * digit (user 142 -> PTN01428). Derived from the id, never stored. The check digit catches every
 * single-digit typo and every adjacent swap, so the statement matcher never credits the wrong member.
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
        return preg_match('/^'.self::PREFIX.'(\d{4})(\d)\z/i', $code, $m) === 1
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

    private const DAMM = [
        [0, 3, 1, 7, 5, 9, 8, 6, 4, 2],
        [7, 0, 9, 2, 1, 5, 4, 8, 6, 3],
        [4, 2, 0, 6, 8, 7, 1, 3, 5, 9],
        [1, 7, 5, 0, 9, 8, 3, 4, 2, 6],
        [6, 1, 2, 3, 0, 4, 5, 9, 7, 8],
        [3, 6, 7, 4, 2, 0, 9, 5, 8, 1],
        [5, 8, 6, 9, 7, 2, 0, 1, 3, 4],
        [8, 9, 4, 5, 3, 6, 2, 0, 1, 7],
        [9, 4, 3, 8, 6, 1, 7, 2, 0, 5],
        [2, 5, 8, 1, 4, 3, 6, 7, 9, 0],
    ];

    /** Damm check digit: the interim digit after running the payload through the quasigroup table. */
    private static function checkDigit(string $payload): int
    {
        $interim = 0;
        foreach (str_split($payload) as $digit) {
            $interim = self::DAMM[$interim][(int) $digit];
        }

        return $interim;
    }
}
