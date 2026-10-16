<?php

namespace App\Filament\Resources\FeePeriods\Tables;

use App\Support\StatusLabel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeePeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('Member'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('month_date')
                    ->label(__('Billing month'))
                    ->date('F Y')
                    ->sortable(),
                TextColumn::make('amount_due')
                    ->money('MYR')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusLabel::for($state))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partial' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusLabel::for($state))
                    ->color('gray')
                    ->toggleable(),
            ])
            ->defaultSort('month_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(StatusLabel::options(['unpaid', 'partial', 'paid'])),
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
