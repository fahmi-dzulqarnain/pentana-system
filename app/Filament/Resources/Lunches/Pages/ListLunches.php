<?php

namespace App\Filament\Resources\Lunches\Pages;

use App\Filament\Resources\Lunches\LunchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLunches extends ListRecords
{
    protected static string $resource = LunchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
