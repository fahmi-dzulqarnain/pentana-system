<?php

namespace App\Filament\Resources\LunchPlans\Pages;

use App\Filament\Resources\LunchPlans\LunchPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditLunchPlan extends EditRecord
{
    protected static string $resource = LunchPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
