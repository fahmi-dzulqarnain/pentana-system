<?php

namespace App\Filament\Resources\Lunches\Tables;

use App\Models\Lunch;
use App\Services\LunchPublisher;
use App\Support\StatusLabel;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
                    // Publish a week in one go: members get one notification listing every day.
                    BulkAction::make('publish')
                        ->label(__('Publish'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription(__('Selected draft days are published and members get one notification listing them. Days already published are skipped.'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $published = app(LunchPublisher::class)->publish($records);

                            $published->isEmpty()
                                ? Notification::make()->title(__('No draft days selected'))->warning()->send()
                                : Notification::make()
                                    ->title(trans_choice('Published :count day: members notified|Published :count days: members notified', $published->count()))
                                    ->success()
                                    ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
