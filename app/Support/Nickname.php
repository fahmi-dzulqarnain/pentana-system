<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;

class Nickname
{
    public const MAX_LENGTH = 30;

    /** Case-insensitive unique index on users (lower(nickname)). */
    public const UNIQUE_INDEX = 'users_nickname_lower_unique';

    /**
     * Whether a unique violation came from the nickname index rather than another column
     * (e.g. email). SQLite and Postgres both name the index in the message.
     */
    public static function isTakenViolation(UniqueConstraintViolationException $e): bool
    {
        return str_contains($e->getMessage(), self::UNIQUE_INDEX);
    }

    public static function normalise(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Rules apply to the trimmed value, so the web form (which validates raw state) and the
     * API (which normalises first) agree. Blank passes: it means "no nickname".
     *
     * @return array<int, mixed>
     */
    public static function rules(?User $ignore = null): array
    {
        return [
            'nullable',
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                $value = self::normalise(is_string($value) ? $value : null);

                if ($value !== null && mb_strlen($value) > self::MAX_LENGTH) {
                    $fail(__('validation.max.string', ['attribute' => mb_strtolower(__('Nickname')), 'max' => self::MAX_LENGTH]));
                }
            },
            function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
                $value = self::normalise(is_string($value) ? $value : null);

                if ($value === null) {
                    return;
                }

                $taken = User::query()
                    // The DB's own lower(), so validation folds case exactly as the unique index does.
                    ->whereRaw('lower(nickname) = lower(?)', [$value])
                    ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
                    ->exists();

                if ($taken) {
                    $fail(__('That nickname is already taken.'));
                }
            },
        ];
    }
}
