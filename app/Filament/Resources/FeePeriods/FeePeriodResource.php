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
use UnitEnum;

class FeePeriodResource extends Resource
{
    protected static ?string $model = FeePeriod::class;

    protected static ?string $recordTitleAttribute = 'Fee Period';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Calendar;

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
