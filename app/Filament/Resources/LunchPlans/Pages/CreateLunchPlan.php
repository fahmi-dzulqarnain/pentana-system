<?php

namespace App\Filament\Resources\LunchPlans\Pages;

use App\Filament\Resources\LunchPlans\LunchPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLunchPlan extends CreateRecord
{
    protected static string $resource = LunchPlanResource::class;
}
