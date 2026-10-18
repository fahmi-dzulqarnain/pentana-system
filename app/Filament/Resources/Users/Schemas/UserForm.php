<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Resources\Users\UserRoleGuard;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    /** Finance accounts' credentials and admin switch are locked for plain admins. */
    private static function lockedFor(?User $record): bool
    {
        return $record !== null
            && UserRoleGuard::isProtected($record)
            && ! auth()->user()?->isSuperAdmin();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label(__('Email address'))
                    ->email()
                    ->required()
                    ->disabled(fn (?User $record): bool => static::lockedFor($record)),
                DatePicker::make('birthday')
                    ->native(false)
                    ->maxDate(today())
                    ->helperText(__('Set or correct the member\'s date of birth.')),
                DateTimePicker::make('email_verified_at')
                    ->native(false)
                    ->seconds(false)
                    ->disabled(fn (?User $record): bool => static::lockedFor($record)),
                TextInput::make('password')
                    ->password()
                    // Required when creating; on edit, leave blank to keep the current password.
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->disabled(fn (?User $record): bool => static::lockedFor($record)),
                Toggle::make('is_admin')
                    ->label(__('Administrator'))
                    ->helperText(__('Admins manage all members and bills, and verify payments.'))
                    ->disabled(fn (?User $record): bool => static::lockedFor($record)),
                Toggle::make('is_treasurer')
                    ->label(__('Treasurer'))
                    ->helperText(__('Can manage finance: payments, bills and the finance summary.'))
                    ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin()),
                Toggle::make('is_super_admin')
                    ->label(__('Super-admin'))
                    ->helperText(__('Full access, including granting roles.'))
                    ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin()),
                Select::make('member_category_id')
                    ->relationship('memberCategory', 'name')
                    ->required()
                    ->live(),
                TextInput::make('custom_fee')
                    ->label(__('Custom monthly fee (override)'))
                    ->numeric()
                    ->prefix('MYR')
                    ->helperText(__('Leave empty to use the Category default fee.')),
                // Credit is derived from unallocated payment proofs, so it is read-only here.
                Placeholder::make('credit')
                    ->label(__('Available credit'))
                    ->content(fn ($record): string => 'MYR '.number_format((float) ($record?->credit ?? 0), 2)),
            ]);
    }
}
