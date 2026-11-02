<?php

namespace App\Filament\Resources\BankStatements\Pages;

use App\Filament\Resources\BankStatements\BankStatementImportResource;
use Filament\Resources\Pages\ViewRecord;

class ViewBankStatementImport extends ViewRecord
{
    protected static string $resource = BankStatementImportResource::class;

    public function getTitle(): string
    {
        return __('Bank statement :from - :to', [
            'from' => $this->record->statement_from->format('d M Y'),
            'to' => $this->record->statement_to->format('d M Y'),
        ]);
    }
}
