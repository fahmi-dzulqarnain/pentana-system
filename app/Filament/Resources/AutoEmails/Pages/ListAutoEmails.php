<?php

namespace App\Filament\Resources\AutoEmails\Pages;

use App\Filament\Concerns\HidesListBreadcrumb;
use App\Filament\Resources\AutoEmails\AutoEmailResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAutoEmails extends ListRecords
{
    use HidesListBreadcrumb;

    protected static string $resource = AutoEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
