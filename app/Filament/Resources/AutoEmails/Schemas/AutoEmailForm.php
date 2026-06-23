<?php

namespace App\Filament\Resources\AutoEmails\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AutoEmailForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options(['one_off' => 'One-off', 'birthday' => 'Birthday'])
                    ->default('one_off')
                    ->required()
                    ->live(),
                TextInput::make('subject')->required()->maxLength(255),
                Textarea::make('body')
                    ->required()
                    ->rows(6)
                    ->columnSpanFull()
                    ->helperText(fn (Get $get): ?string => $get('type') === 'birthday'
                        ? 'Use {name} where the member\'s name should appear.'
                        : null),
                DateTimePicker::make('send_at')
                    ->label('Send at')
                    ->helperText('Leave blank to keep as a draft.')
                    ->native(false)
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('type') === 'one_off'),
                Select::make('status')
                    ->options(['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sent' => 'Sent'])
                    ->default('draft')
                    ->required()
                    ->visible(fn (Get $get): bool => $get('type') === 'one_off'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('When on, this birthday email is sent to members on their birthday.')
                    ->visible(fn (Get $get): bool => $get('type') === 'birthday'),
            ]);
    }
}
