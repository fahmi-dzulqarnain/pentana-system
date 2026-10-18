<?php

namespace App\Filament\Resources\Users;

use App\Models\User;

/**
 * Server-side gate for the finance roles on the Users resource. The form's
 * disabled/hidden fields are cosmetic; this strips anything the actor may not change.
 */
class UserRoleGuard
{
    /** Fields on a protected account that only a super-admin may change. */
    private const PROTECTED_FIELDS = ['password', 'email', 'email_verified_at', 'is_admin'];

    public static function isProtected(User $record): bool
    {
        return $record->is_super_admin || $record->is_treasurer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data, User $actor, ?User $record): array
    {
        if (! $actor->isSuperAdmin()) {
            unset($data['is_super_admin'], $data['is_treasurer']);

            if ($record !== null && static::isProtected($record)) {
                $data = array_diff_key($data, array_flip(self::PROTECTED_FIELDS));
            }

            return $data;
        }

        // A super-admin cannot lock themself out of the admin area or lose their own role.
        if ($record !== null && $record->is($actor)) {
            foreach (['is_admin', 'is_super_admin'] as $field) {
                if (array_key_exists($field, $data) && ! $data[$field]) {
                    unset($data[$field]);
                }
            }
        }

        return $data;
    }
}
