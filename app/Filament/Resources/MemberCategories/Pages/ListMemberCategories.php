<?php

namespace App\Filament\Resources\MemberCategories\Pages;

use App\Filament\Resources\MemberCategories\MemberCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMemberCategories extends ListRecords
{
    protected static string $resource = MemberCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
