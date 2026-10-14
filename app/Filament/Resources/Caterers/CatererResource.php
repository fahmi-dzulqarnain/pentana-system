<?php

namespace App\Filament\Resources\Caterers;

use App\Filament\Resources\Caterers\Pages\CreateCaterer;
use App\Filament\Resources\Caterers\Pages\EditCaterer;
use App\Filament\Resources\Caterers\Pages\ListCaterers;
use App\Filament\Resources\Caterers\RelationManagers\CatererMealsRelationManager;
use App\Filament\Resources\Caterers\Schemas\CatererForm;
use App\Filament\Resources\Caterers\Tables\CaterersTable;
use App\Models\Caterer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CatererResource extends Resource
{
    protected static ?string $model = Caterer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingStorefront;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Welfare & Social');
    }

    public static function getNavigationLabel(): string
    {
        return __('Caterers');
    }

    public static function getModelLabel(): string
    {
        return __('caterer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('caterers');
    }

    public static function form(Schema $schema): Schema
    {
        return CatererForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CaterersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CatererMealsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCaterers::route('/'),
            'create' => CreateCaterer::route('/create'),
            'edit' => EditCaterer::route('/{record}/edit'),
        ];
    }
}
