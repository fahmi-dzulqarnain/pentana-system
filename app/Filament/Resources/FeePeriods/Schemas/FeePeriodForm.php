<?php

namespace App\Filament\Resources\FeePeriods\Schemas;

use App\Models\Payment\FeePeriod;
use App\Models\User;
use App\Support\StatusLabel;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class FeePeriodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    // Disabled members are hidden, except the one an existing bill already belongs to.
                    ->relationship('user', 'name', fn (Builder $query, ?FeePeriod $record) => $query
                        ->where(fn (Builder $q) => $q->enabled()->when($record, fn (Builder $q) => $q->orWhere('id', $record->user_id))))
                    ->searchable()
                    ->required()
                    ->live()
                    // Pre-fill with what this member actually owes (custom fee, else category rate).
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($member = User::find($state)) {
                            $set('amount_due', $member->active_fee);
                        }
                    }),
                DatePicker::make('month_date')
                    ->label(__('Billing month'))
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
