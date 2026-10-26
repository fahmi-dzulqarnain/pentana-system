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
                    $path = $data['file'] ?? null;
                    $disk = Storage::disk('local');

                    // The value is form state from the browser: only a file directly inside
                    // bank-statements/ is ever handed to an importer that deletes what it reads.
                    if (! is_string($path) || ! preg_match('#^bank-statements/[^/]+$#', $path) || str_contains($path, '..') || ! $disk->exists($path)) {
                        Notification::make()->title(__('The uploaded file could not be found. Please upload it again.'))->danger()->send();
                        $action->halt();

                        return;
                    }

                    try {
                        // Re-checked here: the action is callable from the browser, not just visible there.
                        abort_unless((bool) auth()->user()?->canImportBankStatements(), 403);

                        $import = app(StatementImporter::class)->import($disk->path($path), auth()->user());
                    } catch (StatementParseException|DuplicateStatementException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    } finally {
                        // The importer deletes the file itself; this covers a refusal or error before it ran.
                        $disk->delete($path);
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
