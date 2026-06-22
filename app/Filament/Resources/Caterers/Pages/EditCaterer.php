<?php

namespace App\Filament\Resources\Caterers\Pages;

use App\Filament\Resources\Caterers\CatererResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCaterer extends EditRecord
{
    protected static string $resource = CatererResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
