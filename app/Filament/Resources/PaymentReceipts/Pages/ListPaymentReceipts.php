<?php

namespace App\Filament\Resources\PaymentReceipts\Pages;

use App\Filament\Concerns\HidesListBreadcrumb;
use App\Filament\Resources\PaymentReceipts\PaymentReceiptResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentReceipts extends ListRecords
{
    use HidesListBreadcrumb;

    protected static string $resource = PaymentReceiptResource::class;
}
