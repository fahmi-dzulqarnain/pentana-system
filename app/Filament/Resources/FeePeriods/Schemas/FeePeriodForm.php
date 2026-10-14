<?php

namespace App\Filament\Resources\FeePeriods\Schemas;

use App\Support\StatusLabel;
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
                    ->label(__('Billing Month'))
                    ->format('Y-m-01') // Force start of month
                    ->required()
                    ->native(false),
                TextInput::make('amount_due')
                    ->numeric()
                    ->prefix('MYR')
                    ->default(100)
                    ->required(),
                Select::make('type')
                    ->options(StatusLabel::options(['regular', 'free', 'adjustment']))
                    ->live(),
                // ->afterStateUpdated(fn ($state, set) =>
                //     $state === 'free' ? set('amount_due', 0) : null
                // ),
                Textarea::make('remarks')
                    ->placeholder(__('Reason for free month or adjustment')),
            ]);
    }
}
