<?php

namespace App\Filament\Resources\BankStatements;

use App\Filament\NavGroup;
use App\Filament\Resources\BankStatements\Pages\ListBankStatementImports;
use App\Filament\Resources\BankStatements\Pages\ViewBankStatementImport;
use App\Filament\Resources\BankStatements\RelationManagers\LinesRelationManager;
use App\Filament\Support\MemberName;
use App\Models\Payment\BankStatementImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The treasurer's bank statement imports: upload a CIMB statement, see what it verified
 * and which credits still need a human. Read-only apart from the upload.
 */
class BankStatementImportResource extends Resource
{
    protected static ?string $model = BankStatementImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('bank statement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('bank statements');
    }

    public static function getNavigationLabel(): string
    {
        return __('Bank statements');
    }

    // Importing is the club treasurer's job; a super admin who is not treasurer is refused.
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->canImportBankStatements();
    }

    public static function canView(Model $record): bool
    {
        return (bool) auth()->user()?->canImportBankStatements();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('uploader'))
            ->columns([
                TextColumn::make('statement_from')
                    ->label(__('Statement period'))
                    ->formatStateUsing(fn (BankStatementImport $record): string => $record->statement_from->format('d M Y').' - '.$record->statement_to->format('d M Y')),
                MemberName::column('uploader')->label(__('Uploaded by')),
                TextColumn::make('auto_verified')->label(__('Auto-verified'))->numeric(),
                TextColumn::make('confirmed')->label(__('Confirmed'))->numeric(),
                TextColumn::make('unmatched')->label(__('To review'))->numeric(),
                TextColumn::make('created_at')->label(__('Imported'))->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (BankStatementImport $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankStatementImports::route('/'),
            'view' => ViewBankStatementImport::route('/{record}'),
        ];
    }
}
