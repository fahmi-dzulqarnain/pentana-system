<?php

namespace App\Filament\Resources\FeePeriods\Pages;

use App\Filament\Concerns\HidesListBreadcrumb;
use App\Filament\Resources\FeePeriods\FeePeriodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeePeriods extends ListRecords
{
    use HidesListBreadcrumb;

    protected static string $resource = FeePeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
