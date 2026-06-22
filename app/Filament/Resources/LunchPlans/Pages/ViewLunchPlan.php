<?php

namespace App\Filament\Resources\LunchPlans\Pages;

use App\Filament\Resources\LunchPlans\LunchPlanResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLunchPlan extends ViewRecord
{
    protected static string $resource = LunchPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
