<?php

namespace App\Filament\Resources\FeePeriods\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class FeePeriodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required(),
                DatePicker::make('month_date')
                    ->label('Billing Month')
                    ->format('Y-m-01') // Force start of month
                    ->required(),
                TextInput::make('amount_due')
                    ->numeric()
                    ->prefix('MYR')
                    ->default(100)
                    ->required(),
                Select::make('type')
                    ->options([
                        'regular' => 'Regular Fee',
                        'free' => 'Free Month',
                        'adjustment' => 'Manual Adjustment',
                    ])
                    ->live(),
                    // ->afterStateUpdated(fn ($state, set) =>
                    //     $state === 'free' ? set('amount_due', 0) : null
                    // ),
                Textarea::make('remarks')
                    ->placeholder('Reason for free month or adjustment'),
            ]);
    }
}
