<?php

namespace App\Filament\Resources\Lunches\Tables;

use App\Support\StatusLabel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LunchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('caterer.name')->searchable(),
                TextColumn::make('catererMeal.name')->label(__('Menu'))->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusLabel::for($state))
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'closed' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('deadline')->dateTime(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(StatusLabel::options(['draft', 'published', 'closed'])),
            ])
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
