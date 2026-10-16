<?php

namespace App\Filament\Resources\Caterers\Pages;

use App\Filament\Concerns\HidesListBreadcrumb;
use App\Filament\Resources\Caterers\CatererResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCaterers extends ListRecords
{
    use HidesListBreadcrumb;

    protected static string $resource = CatererResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
