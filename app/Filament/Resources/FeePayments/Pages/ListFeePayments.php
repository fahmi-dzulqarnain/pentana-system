<?php

namespace App\Filament\Resources\FeePayments\Pages;

use App\Filament\Concerns\HidesListBreadcrumb;
use App\Filament\Resources\FeePayments\FeePaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeePayments extends ListRecords
{
    use HidesListBreadcrumb;

    protected static string $resource = FeePaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
