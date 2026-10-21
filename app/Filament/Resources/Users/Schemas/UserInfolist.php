<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Support\MemberName;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                MemberName::entry(),
                TextEntry::make('email')
                    ->label(__('Email address')),
                TextEntry::make('email_verified_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('credit')
                    ->label(__('Available credit'))
                    ->money('MYR'),
            ]);
    }
}
