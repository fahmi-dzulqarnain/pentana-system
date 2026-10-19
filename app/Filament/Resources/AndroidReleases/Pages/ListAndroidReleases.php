<?php

namespace App\Filament\Resources\AndroidReleases\Pages;

use App\Filament\Concerns\HidesListBreadcrumb;
use App\Filament\Resources\AndroidReleases\AndroidReleaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAndroidReleases extends ListRecords
{
    use HidesListBreadcrumb;

    protected static string $resource = AndroidReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
