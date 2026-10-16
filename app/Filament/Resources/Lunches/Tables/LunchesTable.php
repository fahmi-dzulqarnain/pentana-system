<?php

namespace App\Filament\Resources\Lunches\Tables;

use App\Models\Lunch;
use App\Support\StatusLabel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LunchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('menus.caterer', 'menus.catererMeal'))
            ->columns([
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('menus_label')
                    ->label(__('Menus'))
                    ->state(fn (Lunch $record): string => $record->menus
                        ->map(fn ($menu) => $menu->label())
                        ->implode(', ')),
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
