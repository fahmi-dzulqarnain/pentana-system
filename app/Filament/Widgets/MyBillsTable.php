<?php

namespace App\Filament\Widgets;

use App\Models\Payment\FeePeriod;
use App\Support\StatusLabel;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class MyBillsTable extends TableWidget
{
    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 'full';

    /**
     * Members see their bills here; admins use the full Fee Periods resource.
     */
    public static function canView(): bool
    {
        return ! (bool) auth()->user()?->is_admin;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Bill history'))
            ->query(fn () => FeePeriod::forMember(auth()->id()))
            ->columns([
                TextColumn::make('month_date')
                    ->label(__('Billing month'))
                    ->date('F Y')
                    ->sortable(),
                TextColumn::make('amount_due')
                    ->label(__('Amount'))
                    ->money('MYR')
                    ->sortable(),
                TextColumn::make('allocations_sum_amount_allocated')
                    ->label(__('Paid'))
                    ->money('MYR')
                    ->default(0),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, FeePeriod $record): string => $record->type === 'free' && $state === 'paid' ? __('Free') : StatusLabel::for($state))
                    ->color(fn (string $state, FeePeriod $record): string => match (true) {
                        $record->type === 'free' => 'gray',
                        $state === 'paid' => 'success',
                        $state === 'partial' => 'warning',
                        default => 'danger',
                    }),
            ])
            ->defaultSort('month_date', 'desc');
    }
}
