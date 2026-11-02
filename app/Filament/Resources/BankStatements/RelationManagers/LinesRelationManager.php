<?php

namespace App\Filament\Resources\BankStatements\RelationManagers;

use App\Enums\StatementLineOutcome;
use App\Filament\Resources\FeePayments\FeePaymentResource;
use App\Filament\Support\MemberName;
use App\Models\Payment\BankStatementLine;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Statement lines');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('txn_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('type')->label(__('Type')),
                TextColumn::make('amount')->label(__('Amount'))->money('MYR')->sortable(),
                TextColumn::make('reference_text')->label(__('Reference'))->wrap(),
                MemberName::column('user')->label(__('Member')),
                TextColumn::make('outcome')
                    ->label(__('Outcome'))
                    ->badge()
                    ->formatStateUsing(fn (StatementLineOutcome $state): string => $state->label())
                    ->color(fn (StatementLineOutcome $state): string => match ($state) {
                        StatementLineOutcome::AutoVerified, StatementLineOutcome::Confirmed => 'success',
                        StatementLineOutcome::NeedsReview => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('txn_date', 'desc')
            ->filters([
                SelectFilter::make('outcome')
                    ->label(__('Outcome'))
                    ->options(collect(StatementLineOutcome::cases())->mapWithKeys(fn (StatementLineOutcome $case): array => [$case->value => $case->label()])->all()),
            ])
            ->recordActions([
                Action::make('proof')
                    ->label(__('View proof'))
                    ->icon('heroicon-m-document-text')
                    ->color('gray')
                    ->visible(fn (BankStatementLine $record): bool => $record->payment_proof_id !== null)
                    ->url(fn (BankStatementLine $record): string => FeePaymentResource::getUrl('view', ['record' => $record->payment_proof_id])),
            ]);
    }
}
