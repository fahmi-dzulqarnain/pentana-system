<?php

namespace App\Filament\Resources\Lunches\Schemas;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Support\StatusLabel;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class LunchForm
{
    public static function configure(Schema $schema): Schema
    {
        // Once ordering has closed, the day's menus, toggles and counts are frozen. Livewire state is
        // tamperable, so the guard is server-side: saved(false) keeps Filament from writing the
        // repeaters whatever the state says (disabled() also does, but only via Filament's
        // disabled-means-not-saved rule), and EditLunch skips the pick changes.
        // Edit only: on create the new record already exists when relationships save.
        $locked = static fn ($livewire): bool => $livewire instanceof EditRecord
            && ($record = $livewire->getRecord()) instanceof Lunch
            && $record->menusAreLocked();

        return $schema
            ->components([
                DatePicker::make('date')->required()->unique(ignoreRecord: true)->default(fn () => request('date'))->native(false),
                DateTimePicker::make('deadline')->required()->native(false)->seconds(false),
                // Status is system-managed: draft -> published via the Publish action; 'closed' is set by Phase 3 (deadline close).
                Select::make('status')
                    ->options(StatusLabel::options(['draft', 'published', 'closed']))
                    ->default('draft')->disabled()->dehydrated(),
                // One or more menus per day. Options are auto-generated per menu on create (see CreateLunch);
                // the nested repeater lets admins tune them on edit.
                Repeater::make('menus')
                    ->label(__('Menus'))
                    ->relationship('menus')
                    ->orderColumn('sort')
                    ->minItems(1)
                    ->required()
                    ->addActionLabel(__('Add menu'))
                    ->disabled($locked)
                    ->saved(fn ($livewire): bool => ! $locked($livewire))
                    ->helperText(fn ($livewire) => $locked($livewire) ? __('Ordering has closed, so this day\'s menus can no longer change.') : null)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('caterer_id')
                            ->label(__('Caterer'))
                            ->options(fn () => Caterer::orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('caterer_meal_id', null)),
                        Select::make('caterer_meal_id')
                            ->label(__('Menu'))
                            ->options(fn (Get $get) => $get('caterer_id')
                                ? CatererMeal::where('caterer_id', $get('caterer_id'))->pluck('name', 'id')
                                : [])
                            ->required()
                            ->distinct(),
                        Repeater::make('options')
                            ->relationship('options')
                            ->schema([
                                Select::make('meal_option_id')->relationship('mealOption', 'name')->disabled(),
                                Toggle::make('enabled')->default(true),
                                TextInput::make('default_count')->numeric()->default(0)->minValue(0),
                            ])
                            ->columns(3)->columnSpanFull()->addable(false)->deletable(false)
                            ->disabled($locked)
                            ->saved(fn ($livewire): bool => ! $locked($livewire))
                            ->hiddenOn('create'),
                    ])
                    ->columns(2),
                Placeholder::make('orders_summary')
                    ->label(__('Current orders'))
                    ->visibleOn('edit')
                    ->columnSpanFull()
                    ->content(function (?Lunch $record): string {
                        if (! $record) {
                            return '-';
                        }

                        $record->loadMissing('responses', 'options.mealOption');
                        $counts = $record->optionCounts();
                        $perOption = $record->options
                            ->map(fn ($o) => ($o->mealOption?->name ?? __('Option')).': '.($counts[$o->meal_option_id] ?? 0))
                            ->implode('   ·   ');

                        $summary = trim($perOption);

                        return ($summary === '' ? '' : $summary.'   ·   ')
                            .__('Not attending: :count', ['count' => $record->notAttendingCount()])
                            .'   ·   '.__('Total ordering: :count', ['count' => $record->attendingCount()]);
                    }),
            ]);
    }
}
