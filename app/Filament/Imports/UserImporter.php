<?php

namespace App\Filament\Imports;

use App\Models\MemberCategory;
use App\Models\User;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class UserImporter extends Importer
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('email')->requiredMapping()->rules(['required', 'email']),
            ImportColumn::make('name')->requiredMapping()->rules(['required', 'string']),
            // 'grade' is not a User attribute — validate it but don't let Filament write it to the model.
            ImportColumn::make('grade')->requiredMapping()->rules(['required', 'string'])->fillRecordUsing(fn () => null),
        ];
    }

    /** Testable core: build a new unclaimed user from a row, or throw to skip/fail it. */
    public static function buildRecord(array $row): User
    {
        $email = trim((string) ($row['email'] ?? ''));

        if (User::where('email', $email)->exists()) {
            throw new RowImportFailedException("An account with email {$email} already exists; skipped.");
        }

        $grade = trim((string) ($row['grade'] ?? ''));
        $category = MemberCategory::whereRaw('lower(name) = ?', [strtolower($grade)])->first();

        if (! $category) {
            throw new RowImportFailedException("Unknown grade '{$grade}'.");
        }

        return new User([
            'name' => trim((string) ($row['name'] ?? '')),
            'email' => $email,
            'member_category_id' => $category->id,
        ]);
    }

    public function resolveRecord(): ?User
    {
        return static::buildRecord($this->data);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return 'Imported '.number_format($import->successful_rows).' member(s). '
            .number_format($import->getFailedRowsCount()).' row(s) skipped or failed.';
    }

    // No queue worker runs in this app — import inline.
    public function getJobConnection(): ?string
    {
        return 'sync';
    }
}
