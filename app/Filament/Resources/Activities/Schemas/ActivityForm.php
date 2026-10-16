<?php

namespace App\Filament\Resources\Activities\Schemas;

use App\Support\StatusLabel;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Str;

class ActivityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Details'))
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        // An event blurb needs emphasis, links and lists; headings, tables, colours
                        // and attachments only make descriptions inconsistent.
                        RichEditor::make('description')
                            ->toolbarButtons([
                                ['bold', 'italic', 'link'],
                                ['bulletList', 'orderedList'],
                                ['undo', 'redo'],
                            ]),
                        TextInput::make('location')
                            ->maxLength(255),
                    ]),
                // In the order an admin thinks about an event: when it starts and ends, then
                // when sign-ups stop.
                Section::make(__('When'))
                    ->columnSpanFull()
                    ->columns(['md' => 2])
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->label(__('Starts at'))
                            ->required()
                            ->native(false)
                            ->seconds(false),
                        DateTimePicker::make('ends_at')
                            ->label(__('Ends at'))
                            ->native(false)
                            ->seconds(false)
                            ->after('starts_at'),
                        DateTimePicker::make('registration_closes_at')
                            ->label(__('Registration closes'))
                            ->native(false)
                            ->seconds(false)
                            ->before('starts_at')
                            ->helperText(__('Leave blank to close when the activity starts. Must be before the start time.')),
                    ]),
                Section::make(__('Registration'))
                    ->columnSpanFull()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('capacity')
                            ->numeric()
                            ->minValue(1)
                            ->helperText(__('Leave blank for unlimited.')),
                        // Status is system-managed: draft -> published via Publish, -> cancelled via Cancel.
                        // Shown read-only and never written back by the form (dehydrated false).
                        Select::make('status')
                            ->options(StatusLabel::options(['draft', 'published', 'cancelled']))
                            ->default('draft')
                            ->disabled()
                            ->dehydrated(false),
                        // Optional per-activity questions members answer when registering.
                        Repeater::make('registration_fields')
                            ->label(__('Registration questions'))
                            ->addActionAlignment(Alignment::Start)
                            ->helperText(__('Optional. Questions members answer when they register.'))
                            ->columnSpanFull()
                            ->addActionLabel(__('Add question'))
                            ->reorderable()
                            ->collapsible()
                            ->default([])
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->schema([
                                // Stable id so renaming a label never orphans existing answers.
                                Hidden::make('key')->default(fn (): string => (string) Str::uuid()),
                                TextInput::make('label')->required(),
                                Select::make('type')
                                    ->options([
                                        'text' => __('Short text'),
                                        'textarea' => __('Long text'),
                                        'select' => __('Dropdown'),
                                        'checkbox' => __('Checkbox'),
                                    ])
                                    ->default('text')
                                    ->required()
                                    ->live(),
                                Toggle::make('required')->default(false),
                                TagsInput::make('options')
                                    ->label(__('Choices'))
                                    ->helperText(__('One tag per choice.'))
                                    ->visible(fn (Get $get): bool => $get('type') === 'select'),
                            ]),
                    ]),
            ]);
    }
}
