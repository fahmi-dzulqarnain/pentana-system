<?php

namespace App\Filament\Resources\PaymentReceipts;

use App\Filament\NavGroup;
use App\Filament\Resources\PaymentReceipts\Pages\ListPaymentReceipts;
use App\Filament\Resources\PaymentReceipts\Tables\PaymentReceiptsTable;
use App\Models\Payment\PaymentReceipt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PaymentReceiptResource extends Resource
{
    protected static ?string $model = PaymentReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('receipt');
    }

    public static function getPluralModelLabel(): string
    {
        return __('receipts');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->canManageFinance();
    }

    // Read-only: receipts are issued by verifying a payment and are never edited by hand.
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
        return PaymentReceiptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentReceipts::route('/'),
        ];
    }
}
