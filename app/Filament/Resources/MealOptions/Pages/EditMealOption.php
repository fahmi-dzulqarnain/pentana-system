<?php

namespace App\Filament\Resources\MealOptions\Pages;

use App\Filament\Resources\MealOptions\MealOptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMealOption extends EditRecord
{
    protected static string $resource = MealOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
