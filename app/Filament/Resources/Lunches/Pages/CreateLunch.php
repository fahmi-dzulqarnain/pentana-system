<?php

namespace App\Filament\Resources\Lunches\Pages;

use App\Filament\Resources\Lunches\LunchResource;
use App\Services\LunchPlanningService;
use Filament\Resources\Pages\CreateRecord;

class CreateLunch extends CreateRecord
{
    protected static string $resource = LunchResource::class;

    protected function afterCreate(): void
    {
        app(LunchPlanningService::class)->syncOptionsFromMenu($this->record);
    }
}
