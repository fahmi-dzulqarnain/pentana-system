<?php

namespace App\Filament\Resources\SupportTickets\Schemas;

use App\Enums\SupportTicketType;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;

class SupportTicketForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ToggleButtons::make('type')
                    ->label(__('Type'))
                    ->options(SupportTicketType::class)
                    ->default(SupportTicketType::Question->value)
                    ->inline()
                    ->required(),
                TextInput::make('subject')
                    ->label(__('Subject'))
                    ->required()
                    ->maxLength(150),
                Textarea::make('body')
                    ->label(__('Message'))
                    ->required()
                    ->maxLength(5000)
                    ->rows(6),
                // Private disk: screenshots are only served to the member and admins (SupportAttachmentController).
                FileUpload::make('attachment')
                    ->label(__('Screenshot (optional)'))
                    ->image()
                    ->disk('local')
                    ->directory('support')
                    ->visibility('private')
                    ->maxSize(5120),
            ])
            ->columns(1);
    }
}
