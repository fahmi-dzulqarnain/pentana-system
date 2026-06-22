<?php

namespace App\Filament\Resources\MealOptions\Pages;

use App\Filament\Resources\MealOptions\MealOptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMealOptions extends ListRecords
{
    protected static string $resource = MealOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
