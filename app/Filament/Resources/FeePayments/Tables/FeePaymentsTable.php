<?php

namespace App\Filament\Resources\FeePayments\Tables;

use App\Models\Payment\PaymentProof;
use App\Services\FinanceService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeePaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->searchable(),
                TextColumn::make('amount_claimed')->money('MYR'),
                IconColumn::make('status')
                    ->icon(fn (string $state) => match ($state) {
                        'pending' => 'heroicon-o-clock',
                        'verified' => 'heroicon-o-check-badge',
                        'rejected' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-question-mark-circle',
                    }),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('Verify Payment')
                    ->action(function (PaymentProof $record, array $data, FinanceService $service) {
                        $service->verifyAndAllocate($record, $data['actual_amount']);
                    })
                    ->schema([
                        TextInput::make('actual_amount')
                            ->numeric()
                            ->default(fn ($record) => $record->amount_claimed)
                            ->required(),
                    ])
                    ->visible(fn ($record) => $record->status === 'pending' && auth()->user()?->is_admin)
                    ->color('success')
                    ->icon('heroicon-o-banknotes'),
                Action::make('Reject Payment')
                    ->action(function (PaymentProof $record, array $data) {
                        // A rejected proof is never allocated, so no credit is granted.
                        $record->update([
                            'status' => 'rejected',
                            'admin_note' => $data['admin_note'],
                        ]);
                    })
                    ->schema([
                        Textarea::make('admin_note')
                            ->label('Reason for rejection')
                            ->required(),
                    ])
                    ->visible(fn ($record) => $record->status === 'pending' && auth()->user()?->is_admin)
                    ->color('danger')
                    ->icon('heroicon-o-x-circle'),
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
