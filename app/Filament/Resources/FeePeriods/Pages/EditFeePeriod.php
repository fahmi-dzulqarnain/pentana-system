<?php

namespace App\Filament\Resources\FeePeriods\Pages;

use App\Filament\Resources\FeePeriods\FeePeriodResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFeePeriod extends EditRecord
{
    protected static string $resource = FeePeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
