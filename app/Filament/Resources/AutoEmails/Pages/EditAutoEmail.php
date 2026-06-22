<?php

namespace App\Filament\Resources\AutoEmails\Pages;

use App\Filament\Resources\AutoEmails\AutoEmailResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAutoEmail extends EditRecord
{
    protected static string $resource = AutoEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
