<?php

namespace App\Filament\Resources\Caterers\Pages;

use App\Filament\Resources\Caterers\CatererResource;
use App\Filament\Support\GuardsLunchDayDeletes;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCaterer extends EditRecord
{
    protected static string $resource = CatererResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardsLunchDayDeletes::single(DeleteAction::make(), fn (int $count): string => __('This caterer is used by :count lunch days, so it can\'t be deleted.', ['count' => $count])),
        ];
    }
}
