<?php

namespace App\Filament\Widgets;

use App\Models\Payment\FeePeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class MyBillsTable extends TableWidget
{
    protected static ?string $heading = 'Bill history';

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
            ->query(fn () => FeePeriod::forMember(auth()->id()))
            ->columns([
                TextColumn::make('month_date')
                    ->label('Billing Month')
                    ->date('F Y')
                    ->sortable(),
                TextColumn::make('amount_due')
                    ->label('Amount')
                    ->money('MYR')
                    ->sortable(),
                TextColumn::make('allocations_sum_amount_allocated')
                    ->label('Paid')
                    ->money('MYR')
                    ->default(0),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, FeePeriod $record): string => $record->type === 'free' && $state === 'paid' ? 'Free' : ucfirst($state))
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
