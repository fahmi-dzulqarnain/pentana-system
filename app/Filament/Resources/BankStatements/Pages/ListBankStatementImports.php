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
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
                        // Never copied to a disk: the importer reads Livewire's temporary upload
                        // and it is deleted below, so no copy of the full statement is kept.
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data, Action $action) {
                    $file = $data['file'] ?? null;

                    // Only a signed Livewire temporary upload is ever a file object here.
                    if (! $file instanceof TemporaryUploadedFile) {
                        Notification::make()->title(__('The uploaded file could not be found. Please upload it again.'))->danger()->send();
                        $action->halt();

                        return;
                    }

                    try {
                        // Re-checked here: the action is callable from the browser, not just visible there.
                        abort_unless((bool) auth()->user()?->canImportBankStatements(), 403);

                        $import = app(StatementImporter::class)->import($file->getRealPath(), auth()->user());
                    } catch (StatementParseException|DuplicateStatementException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    } finally {
                        // The importer deletes what it read; this covers a refusal or error before it ran.
                        $file->delete();
                    }

                    Notification::make()
                        ->title(__(':verified verified, :confirmed confirmed, :unmatched to review', [
                            // This run's result: it may include earlier imports' lines it resolved.
                            'verified' => $import->matchResult->autoVerified,
                            'confirmed' => $import->matchResult->confirmed,
                            'unmatched' => $import->matchResult->unmatched,
                        ]))
                        ->success()
                        ->send();

                    return redirect(BankStatementImportResource::getUrl('view', ['record' => $import]));
                }),
        ];
    }
}
