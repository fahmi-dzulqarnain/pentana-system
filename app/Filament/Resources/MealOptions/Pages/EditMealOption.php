<?php

namespace App\Filament\Resources\MealOptions\Pages;

use App\Filament\Resources\MealOptions\MealOptionResource;
use App\Filament\Support\GuardsLunchDayDeletes;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMealOption extends EditRecord
{
    protected static string $resource = MealOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardsLunchDayDeletes::single(DeleteAction::make(), fn (int $count): string => __('This option is used by :count lunch days, so it can\'t be deleted.', ['count' => $count])),
        ];
    }
}
