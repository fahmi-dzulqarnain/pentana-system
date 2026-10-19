<?php

namespace App\Filament\Resources\AndroidReleases\Tables;

use App\Models\AndroidRelease;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AndroidReleasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('version_code', 'desc')
            ->columns([
                TextColumn::make('version_name')
                    ->label(__('Version'))
                    ->description(fn (AndroidRelease $record) => __('Build :code', ['code' => $record->version_code]))
                    ->searchable(),
                TextColumn::make('apk_size')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (AndroidRelease $record) => $record->sizeInMegabytes().' MB'),
                TextColumn::make('notes')
                    ->label(__('What\'s new'))
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('Published'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }
}
