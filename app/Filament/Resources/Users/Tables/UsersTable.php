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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                SelectFilter::make('account_status')
                    ->label(__('Account status'))
                    ->options([
                        'admin' => __('Admin'),
                        'claimed' => __('Claimed'),
                        'unclaimed' => __('Not claimed yet'),
                    ])
                    // Same rules as the column: admin first, then whether the account was activated.
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'admin' => $query->where('is_admin', true),
                        'claimed' => $query->where('is_admin', false)->whereNotNull('activated_at'),
                        'unclaimed' => $query->where('is_admin', false)->whereNull('activated_at'),
                        default => $query,
                    }),
                SelectFilter::make('member_category_id')
                    ->label(__('Member category'))
                    ->relationship('memberCategory', 'name'),
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
