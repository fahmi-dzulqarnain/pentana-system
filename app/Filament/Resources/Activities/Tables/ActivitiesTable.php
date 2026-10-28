<?php

namespace App\Filament\Resources\Activities\Tables;

use App\Models\Activity;
use App\Support\StatusLabel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->placeholder(__('Coming soon'))
                    ->description(fn (Activity $record): ?string => $record->isComingSoon() ? $record->timeframe : null)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusLabel::for($state))
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('registered')
                    ->label(__('Registered'))
                    ->state(fn (Activity $record): string => $record->registeredCount().' / '.($record->capacity ?? '∞')),
                TextColumn::make('location')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(StatusLabel::options(['draft', 'published', 'cancelled'])),
            ])
            ->defaultSort('starts_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
