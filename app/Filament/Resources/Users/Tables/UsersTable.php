<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Imports\UserImporter;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ImportAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('Email address'))
                    ->searchable(),
                TextColumn::make('memberCategory.name')
                    ->label(__('Member category')),
                // Whether the member has activated the account an admin created for them.
                TextColumn::make('account_status')
                    ->label(__('Account status'))
                    ->state(fn (User $record): string => match (true) {
                        $record->is_admin => 'admin',
                        $record->activated_at !== null => 'claimed',
                        default => 'unclaimed',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'primary',
                        'claimed' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => __('Admin'),
                        'claimed' => __('Claimed'),
                        default => __('Not claimed yet'),
                    }),
                // Derived from unallocated payment proofs, so not a sortable DB column.
                TextColumn::make('credit')
                    ->label(__('Credit'))
                    ->money('MYR'),
                TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with('memberCategory'))
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                ImportAction::make()
                    ->importer(UserImporter::class)
                    ->label(__('Import members')),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
