<?php

namespace App\Filament\Resources\Activities\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ActivityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->rows(4)
                    ->columnSpanFull(),
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
                DateTimePicker::make('registration_closes_at')
                    ->label('Registration closes')
                    ->native(false)
                    ->seconds(false)
                    ->helperText('Leave blank to close when the activity starts.'),
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
            ]);
    }
}
