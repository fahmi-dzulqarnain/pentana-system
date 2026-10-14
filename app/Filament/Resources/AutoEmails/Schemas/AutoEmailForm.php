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
                TextInput::make('subject')->required()->maxLength(255),
                RichEditor::make('body')
                    ->required()
                    ->columnSpanFull()
                    ->helperText(fn (Get $get): ?string => $get('type') === 'birthday'
                        ? __('Use {name} where the member\'s name should appear.')
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
