<?php

namespace App\Filament\Resources\Activities\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ActivityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                RichEditor::make('description')
                    ->columnSpanFull(),
                DateTimePicker::make('registration_closes_at')
                    ->label('Registration closes')
                    ->native(false)
                    ->seconds(false)
                    ->before('starts_at')
                    ->helperText('Leave blank to close when the activity starts. Must be before the start time.'),
                DateTimePicker::make('starts_at')
                    ->label('Starts at')
                    ->required()
                    ->native(false)
                    ->seconds(false),
                DateTimePicker::make('ends_at')
                    ->label('Ends at')
                    ->native(false)
                    ->seconds(false)
                    ->after('starts_at'),
                TextInput::make('location')
                    ->maxLength(255),
                TextInput::make('capacity')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Leave blank for unlimited.'),
                // Status is system-managed: draft -> published via Publish, -> cancelled via Cancel.
                // Shown read-only and never written back by the form (dehydrated false).
                Select::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published', 'cancelled' => 'Cancelled'])
                    ->default('draft')
                    ->disabled()
                    ->dehydrated(false),
                // Optional per-activity questions members answer when registering.
                Repeater::make('registration_fields')
                    ->label('Registration questions')
                    ->helperText('Optional. Questions members answer when they register.')
                    ->columnSpanFull()
                    ->addActionLabel('Add question')
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
                                'text' => 'Short text',
                                'textarea' => 'Long text',
                                'select' => 'Dropdown',
                                'checkbox' => 'Checkbox',
                            ])
                            ->default('text')
                            ->required()
                            ->live(),
                        Toggle::make('required')->default(false),
                        TagsInput::make('options')
                            ->label('Choices')
                            ->helperText('One tag per choice.')
                            ->visible(fn (Get $get): bool => $get('type') === 'select'),
                    ]),
            ]);
    }
}
