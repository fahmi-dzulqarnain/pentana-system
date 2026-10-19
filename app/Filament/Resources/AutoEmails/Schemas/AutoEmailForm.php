<?php

namespace App\Filament\Resources\AutoEmails\Schemas;

use App\Support\StatusLabel;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
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
                    ->options(StatusLabel::options(['one_off', 'birthday']))
                    ->default('one_off')
                    ->required()
                    ->live(),
                // Birthday templates may leave these blank to send the built-in message,
                // which is translated into each member's language.
                TextInput::make('subject')
                    ->required(fn (Get $get): bool => $get('type') !== 'birthday')
                    ->maxLength(255)
                    ->helperText(fn (Get $get): ?string => $get('type') === 'birthday'
                        ? __('Leave blank to use the built-in subject.')
                        : null),
                RichEditor::make('body')
                    ->required(fn (Get $get): bool => $get('type') !== 'birthday')
                    ->columnSpanFull()
                    ->helperText(fn (Get $get): ?string => $get('type') === 'birthday'
                        ? __('Use {name} where the member\'s name should appear. It uses their nickname if they have one. Leave blank to send the built-in birthday message, translated into each member\'s language.')
                        : null),
                DateTimePicker::make('send_at')
                    ->label(__('Send at'))
                    ->helperText(__('Leave blank to keep as a draft.'))
                    ->native(false)
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('type') === 'one_off'),
                Select::make('status')
                    ->options(StatusLabel::options(['draft', 'scheduled', 'sent']))
                    ->default('draft')
                    ->required()
                    ->visible(fn (Get $get): bool => $get('type') === 'one_off'),
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true)
                    ->helperText(__('When on, this birthday email is sent to members on their birthday.'))
                    ->visible(fn (Get $get): bool => $get('type') === 'birthday'),
            ]);
    }
}
