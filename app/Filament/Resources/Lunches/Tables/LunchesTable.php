<?php

namespace App\Filament\Resources\Lunches\Tables;

use App\Models\Lunch;
use App\Support\StatusLabel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                        ->implode(', '))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $needle = '%'.mb_strtolower($search).'%';

                        // lower() on both sides: case-insensitive on SQLite and Postgres alike.
                        return $query->whereHas('menus', fn (Builder $menus) => $menus
                            ->whereHas('caterer', fn (Builder $q) => $q->whereRaw('lower(name) like ?', [$needle]))
                            ->orWhereHas('catererMeal', fn (Builder $q) => $q->whereRaw('lower(name) like ?', [$needle])));
                    }),
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
