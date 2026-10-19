<?php

namespace App\Filament\Support;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;

/**
 * How admin screens show a member: the nickname (when set) with the full name beneath,
 * so admins can still tell who is who. Search matches both.
 */
class MemberName
{
    public static function column(?string $relationship = null): TextColumn
    {
        $path = $relationship ? "{$relationship}.name" : 'name';

        return TextColumn::make($path)
            ->state(fn ($record): string => self::member($record, $relationship)?->display_name ?? '')
            ->description(fn ($record): ?string => self::secondary($record, $relationship))
            ->weight(FontWeight::Bold)
            ->searchable(['name', 'nickname']);
    }

    public static function entry(?string $relationship = null): TextEntry
    {
        $path = $relationship ? "{$relationship}.name" : 'name';

        return TextEntry::make($path)
            ->state(fn ($record): string => self::member($record, $relationship)?->display_name ?? '')
            ->helperText(fn ($record): ?string => self::secondary($record, $relationship));
    }

    /** "Nickname (Full name)" when a nickname is set, otherwise the full name. */
    public static function optionLabel(User $user): string
    {
        return filled($user->nickname) ? "{$user->nickname} ({$user->name})" : $user->name;
    }

    /** The full name, shown beneath the nickname; nothing when the nickname is absent. */
    public static function secondary($record, ?string $relationship = null): ?string
    {
        $member = self::member($record, $relationship);

        return filled($member?->nickname) ? $member->name : null;
    }

    private static function member($record, ?string $relationship): ?User
    {
        return $relationship ? $record->{$relationship} : $record;
    }
}
