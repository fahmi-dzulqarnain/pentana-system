<?php

namespace App\Filament\Resources\Lunches\Pages;

use App\Filament\Resources\Lunches\LunchResource;
use App\Services\LunchPlanningService;
use Filament\Resources\Pages\CreateRecord;

class CreateLunch extends CreateRecord
{
    protected static string $resource = LunchResource::class;

    /** A new lunch always starts as a draft; the status field is display-only. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'draft';

        return $data;
    }

    protected function afterCreate(): void
    {
        $service = app(LunchPlanningService::class);

        foreach ($this->record->menus as $menu) {
            $service->syncOptionsFromMenu($menu);
        }
    }
}
