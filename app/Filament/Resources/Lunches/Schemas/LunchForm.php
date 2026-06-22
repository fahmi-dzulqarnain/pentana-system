<?php

namespace App\Filament\Resources\Lunches\Schemas;

use App\Models\CatererMeal;
use App\Models\Lunch;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LunchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')->required()->unique(ignoreRecord: true)->default(fn () => request('date'))->native(false),
                Select::make('caterer_id')->relationship('caterer', 'name')->required()->live(),
                Select::make('caterer_meal_id')
                    ->label('Menu')
                    ->options(fn (Get $get) => $get('caterer_id')
                        ? CatererMeal::where('caterer_id', $get('caterer_id'))->pluck('name', 'id')
                        : [])
                    ->required(),
                DateTimePicker::make('deadline')->required()->native(false)->seconds(false),
                // Status is system-managed: draft -> published via the Publish action; 'closed' is set by Phase 3 (deadline close).
                Select::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed'])
                    ->default('draft')->disabled()->dehydrated(),
                // Options are auto-generated on create (see CreateLunch); this repeater lets admins tune them on edit.
                Repeater::make('options')
                    ->relationship('options')
                    ->schema([
                        Select::make('meal_option_id')->relationship('mealOption', 'name')->disabled(),
                        Toggle::make('enabled')->default(true),
                        TextInput::make('default_count')->numeric()->default(0)->minValue(0),
                    ])
                    ->columns(3)->columnSpanFull()->addable(false)->deletable(false)
                    ->hiddenOn('create'),
                Placeholder::make('orders_summary')
                    ->label('Current orders')
                    ->visibleOn('edit')
                    ->columnSpanFull()
                    ->content(function (?Lunch $record): string {
                        if (! $record) {
                            return '—';
                        }

                        $record->loadMissing('responses', 'options.mealOption');
                        $counts = $record->optionCounts();
                        $perOption = $record->options
                            ->map(fn ($o) => ($o->mealOption?->name ?? 'Option').': '.($counts[$o->meal_option_id] ?? 0))
                            ->implode('   ·   ');

                        $summary = trim($perOption);

                        return ($summary === '' ? '' : $summary.'   ·   ')
                            .'Not attending: '.$record->notAttendingCount()
                            .'   ·   Total ordering: '.$record->attendingCount();
                    }),
            ]);
    }
}
