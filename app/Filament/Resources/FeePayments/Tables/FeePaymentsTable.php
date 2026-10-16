<?php

namespace App\Filament\Resources\FeePayments\Tables;

use App\Filament\Resources\FeePayments\Actions\PaymentReviewActions;
use App\Filament\Resources\FeePayments\FeePaymentResource;
use App\Support\StatusLabel;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeePaymentsTable
{
    public static function configure(Table $table): Table
    {
        $isAdmin = fn (): bool => (bool) auth()->user()?->is_admin;

        return $table
            ->columns([
                TextColumn::make('user.name')->searchable(),
                TextColumn::make('amount_claimed')->money('MYR'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => StatusLabel::for($state)),
                TextColumn::make('created_at')
                    ->label(__('Submitted'))
                    ->dateTime('j M Y, H:i')
                    ->sortable(),
            ])
            // Admins work the queue oldest first; members see their latest submission first.
            ->defaultSort('created_at', fn () => $isAdmin() ? 'asc' : 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(StatusLabel::options(['pending', 'verified', 'rejected']))
                    ->default(fn () => $isAdmin() ? 'pending' : null),
            ])
            // Opening a payment means reviewing its proof, so a row leads to the view page, not the edit form.
            ->recordUrl(fn ($record) => FeePaymentResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                // Verify and Reject sit behind a menu so a decision is never one stray click away.
                ActionGroup::make([
                    PaymentReviewActions::verify(),
                    PaymentReviewActions::reject(),
                    EditAction::make(),
                ]),
            ])
            ->stackedOnMobile()
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'pending' => 'warning',
            'verified' => 'success',
            'rejected' => 'danger',
            default => 'gray',
        };
    }
}
