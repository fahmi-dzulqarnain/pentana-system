<?php

namespace App\Filament\Resources\Meals\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MealForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('base_price')->numeric()->prefix('MYR')->required(),
                FileUpload::make('image')->image()->disk('public')->directory('meals')->visibility('public'),
                CheckboxList::make('options')->relationship('options', 'name')->columns(2),
            ]);
    }
}
