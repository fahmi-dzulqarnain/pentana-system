<?php

namespace App\Filament\Resources\LunchPlans;

use App\Filament\Resources\LunchPlans\Pages\CreateLunchPlan;
use App\Filament\Resources\LunchPlans\Pages\EditLunchPlan;
use App\Filament\Resources\LunchPlans\Pages\ListLunchPlans;
use App\Filament\Resources\LunchPlans\Pages\ViewLunchPlan;
use App\Filament\Resources\LunchPlans\Schemas\LunchPlanForm;
use App\Filament\Resources\LunchPlans\Schemas\LunchPlanInfolist;
use App\Filament\Resources\LunchPlans\Tables\LunchPlansTable;
use App\Models\LunchPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class LunchPlanResource extends Resource
{
    protected static ?string $model = LunchPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Welfare & Social';

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->is_admin ? 'Lunch Planner' : 'Lunch';
    }

    // Admins plan lunches; members get a read-only view.
    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function canEdit(Model $record): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return LunchPlanForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LunchPlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LunchPlansTable::configure($table);
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
            'index' => ListLunchPlans::route('/'),
            'create' => CreateLunchPlan::route('/create'),
            'view' => ViewLunchPlan::route('/{record}'),
            'edit' => EditLunchPlan::route('/{record}/edit'),
        ];
    }
}
