<?php

namespace App\Filament\Resources\Meals;

use App\Filament\NavGroup;
use App\Filament\Resources\Meals\Pages\CreateMeal;
use App\Filament\Resources\Meals\Pages\EditMeal;
use App\Filament\Resources\Meals\Pages\ListMeals;
use App\Filament\Resources\Meals\Schemas\MealForm;
use App\Filament\Resources\Meals\Tables\MealsTable;
use App\Models\Meal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MealResource extends Resource
{
    protected static ?string $model = Meal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Cake;

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return NavGroup::Lunch;
    }

    public static function getNavigationLabel(): string
    {
        return __('Meals');
    }

    public static function getModelLabel(): string
    {
        return __('meal');
    }

    public static function getPluralModelLabel(): string
    {
        return __('meals');
    }

    public static function form(Schema $schema): Schema
    {
        return MealForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MealsTable::configure($table);
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
            'index' => ListMeals::route('/'),
            'create' => CreateMeal::route('/create'),
            'edit' => EditMeal::route('/{record}/edit'),
        ];
    }
}
