<?php

namespace App\Filament\Resources\BankStatements\Pages;

use App\Filament\Concerns\HidesListBreadcrumb;
use App\Filament\Resources\BankStatements\BankStatementImportResource;
use App\Services\BankStatements\DuplicateStatementException;
use App\Services\BankStatements\StatementImporter;
use App\Services\BankStatements\StatementParseException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListBankStatementImports extends ListRecords
{
    use HidesListBreadcrumb;

    protected static string $resource = BankStatementImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importStatement')
                ->label(__('Import statement'))
                ->icon('heroicon-m-arrow-up-tray')
                ->authorize(fn (): bool => (bool) auth()->user()?->canImportBankStatements())
                ->schema([
                    FileUpload::make('file')
                        ->label(__('CIMB BizChannel statement (PDF)'))
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(5120)
                        ->disk('local')
                        ->directory('bank-statements')
                        ->required(),
                ])
                ->action(function (array $data, Action $action) {
                    // Re-checked here: the action is callable from the browser, not just visible there.
                    abort_unless((bool) auth()->user()?->canImportBankStatements(), 403);

                    try {
                        $import = app(StatementImporter::class)->import(
                            Storage::disk('local')->path($data['file']),
                            auth()->user(),
                        );
                    } catch (StatementParseException|DuplicateStatementException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->title(__(':verified verified, :confirmed confirmed, :unmatched to review', [
                            'verified' => $import->auto_verified,
                            'confirmed' => $import->confirmed,
                            'unmatched' => $import->unmatched,
                        ]))
                        ->success()
                        ->send();

                    return redirect(BankStatementImportResource::getUrl('view', ['record' => $import]));
                }),
        ];
    }
}
