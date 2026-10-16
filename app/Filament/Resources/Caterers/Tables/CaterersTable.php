<?php

namespace App\Filament\Resources\Caterers\Tables;

use App\Filament\Support\GuardsLunchDayDeletes;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CaterersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact_name')
                    ->toggleable(),
                TextColumn::make('phone')
                    ->toggleable(),
                TextColumn::make('meals_count')->counts('meals')->label(__('Menu items'))->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardsLunchDayDeletes::bulk(DeleteBulkAction::make()),
                ]),
            ]);
    }
}
