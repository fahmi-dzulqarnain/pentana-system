<?php

namespace App\Filament\Resources\AutoEmails\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AutoEmailForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('subject')
                    ->required()
                    ->maxLength(255),
                Textarea::make('body')
                    ->required()
                    ->rows(6)
                    ->columnSpanFull(),
                DateTimePicker::make('send_at')
                    ->label('Send at')
                    ->helperText('Leave blank to keep as a draft.'),
                Select::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'scheduled' => 'Scheduled',
                        'sent' => 'Sent',
                    ])
                    ->default('draft')
                    ->required(),
            ]);
    }
}
