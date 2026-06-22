<?php

namespace App\Filament\Resources\MemberCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MemberCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->placeholder('e.g. Grade 7-10'),
                TextInput::make('default_fee')
                    ->numeric()
                    ->prefix('MYR')
                    ->required(),
            ]);
    }
}
