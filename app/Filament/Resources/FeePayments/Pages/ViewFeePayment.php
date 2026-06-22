<?php

namespace App\Filament\Resources\FeePayments\Pages;

use App\Filament\Resources\FeePayments\FeePaymentResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewFeePayment extends ViewRecord
{
    protected static string $resource = FeePaymentResource::class;

    public function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Section::make('Payment Details')
                    ->schema([
                        TextEntry::make('user.name')->label('Member Name'),
                        TextEntry::make('amount_claimed')->money('MYR'),
                        ViewEntry::make('proof_image_path')
                            ->label('Receipt Proof')
                            ->view('filament.infolists.receipt-proof')
                            ->columnSpanFull(),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'verified' => 'success',
                                'rejected' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))
                            ),
                    ])
                    ->columns(2),

                Section::make('Allocations')
                    ->description('This shows which months this payment covered.')
                    ->schema([
                        RepeatableEntry::make('allocations')
                            ->schema([
                                TextEntry::make('feePeriod.month_date')
                                    ->label('Month')
                                    ->date('F Y'),
                                TextEntry::make('amount_allocated')
                                    ->money('MYR'),
                            ])
                            ->columns(2),
                    ])
                    ->visible(fn ($record) => $record->status === 'verified'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
