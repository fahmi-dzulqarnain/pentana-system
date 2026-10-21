<?php

namespace App\Filament\Resources\PaymentReceipts\Tables;

use App\Filament\Resources\PaymentReceipts\Actions\DownloadReceiptAction;
use App\Models\Payment\PaymentReceipt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label(__('Receipt no.'))->searchable(),
                TextColumn::make('member_name')->label(__('Member name'))->searchable(),
                TextColumn::make('amount_received')->label(__('Amount received'))->money('MYR'),
                TextColumn::make('verified_at')->label(__('Verified'))->dateTime(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (PaymentReceipt $record): string => $record->isVoid() ? __('Void') : __('Valid'))
                    ->color(fn (PaymentReceipt $record): string => $record->isVoid() ? 'danger' : 'success'),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('year')->orderByDesc('sequence'))
            ->filters([
                TernaryFilter::make('voided')
                    ->label(__('Void'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Void only'))
                    ->falseLabel(__('Valid only'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('voided_at'),
                        false: fn (Builder $query) => $query->whereNull('voided_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                DownloadReceiptAction::make(fn (PaymentReceipt $r) => $r),
            ]);
    }
}
