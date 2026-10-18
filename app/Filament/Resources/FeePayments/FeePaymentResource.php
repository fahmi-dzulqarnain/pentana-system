<?php

namespace App\Filament\Resources\FeePayments;

use App\Filament\NavGroup;
use App\Filament\Resources\FeePayments\Pages\CreateFeePayment;
use App\Filament\Resources\FeePayments\Pages\EditFeePayment;
use App\Filament\Resources\FeePayments\Pages\ListFeePayments;
use App\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use App\Filament\Resources\FeePayments\Schemas\FeePaymentForm;
use App\Filament\Resources\FeePayments\Tables\FeePaymentsTable;
use App\Models\Payment\PaymentProof;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FeePaymentResource extends Resource
{
    protected static ?string $model = PaymentProof::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CreditCard;

    public static function getRecordTitle(?Model $record): string
    {
        return $record?->user?->name ?? static::getModelLabel();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['user.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return static::getRecordTitle($record).' · MYR '.number_format((float) $record->amount_claimed, 2);
    }

    // Members reach this as their own payment history; finance users as the verification queue.
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->canManageFinance() ? NavGroup::Finance : NavGroup::Personal;
    }

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->canManageFinance() ? static::getTitleCasePluralModelLabel() : __('My Payments');
    }

    public static function getNavigationSort(): ?int
    {
        return auth()->user()?->canManageFinance() ? 1 : 3;
    }

    public static function getModelLabel(): string
    {
        return __('fee payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fee payments');
    }

    public static function form(Schema $schema): Schema
    {
        return FeePaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeePaymentsTable::configure($table);
    }

    // Members submit their own proofs but cannot edit, delete, or verify them.
    public static function canEdit(Model $record): bool
    {
        return (bool) auth()->user()?->canManageFinance();
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) auth()->user()?->canManageFinance();
    }

    // Members only ever see their own submitted proofs.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()?->canManageFinance()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeePayments::route('/'),
            'create' => CreateFeePayment::route('/create'),
            'edit' => EditFeePayment::route('/{record}/edit'),
            'view' => ViewFeePayment::route('/{record}'),
        ];
    }
}
