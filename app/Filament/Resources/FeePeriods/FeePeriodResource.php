<?php

namespace App\Filament\Resources\FeePeriods;

use App\Filament\Resources\FeePeriods\Pages\CreateFeePeriod;
use App\Filament\Resources\FeePeriods\Pages\EditFeePeriod;
use App\Filament\Resources\FeePeriods\Pages\ListFeePeriods;
use App\Filament\Resources\FeePeriods\Schemas\FeePeriodForm;
use App\Filament\Resources\FeePeriods\Tables\FeePeriodsTable;
use App\Models\Payment\FeePeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FeePeriodResource extends Resource
{
    protected static ?string $model = FeePeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Calendar;

    public static function getRecordTitle(?Model $record): string
    {
        if (! $record) {
            return static::getModelLabel();
        }

        return trim(($record->user?->name ?? '').' · '.$record->month_date?->translatedFormat('F Y'), ' ·');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['user.name'];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('fee period');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fee periods');
    }

    public static function form(Schema $schema): Schema
    {
        return FeePeriodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeePeriodsTable::configure($table);
    }

    // Fee periods are admin-only; members see their bills via the dashboard timeline.
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
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
            'index' => ListFeePeriods::route('/'),
            'create' => CreateFeePeriod::route('/create'),
            'edit' => EditFeePeriod::route('/{record}/edit'),
        ];
    }
}
