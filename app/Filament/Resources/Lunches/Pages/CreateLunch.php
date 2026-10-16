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
        $service = app(LunchPlanningService::class);

        foreach ($this->record->menus as $menu) {
            $service->syncOptionsFromMenu($menu);
        }
    }
}
