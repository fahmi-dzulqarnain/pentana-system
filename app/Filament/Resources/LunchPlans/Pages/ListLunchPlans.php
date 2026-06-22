<?php

namespace App\Filament\Resources\LunchPlans\Pages;

use App\Filament\Resources\LunchPlans\LunchPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLunchPlans extends ListRecords
{
    protected static string $resource = LunchPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
