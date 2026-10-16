<?php

namespace App\Filament\Resources\Lunches;

use App\Filament\NavGroup;
use App\Filament\Resources\Lunches\Pages\CreateLunch;
use App\Filament\Resources\Lunches\Pages\EditLunch;
use App\Filament\Resources\Lunches\Pages\ListLunches;
use App\Filament\Resources\Lunches\Schemas\LunchForm;
use App\Filament\Resources\Lunches\Tables\LunchesTable;
use App\Models\Lunch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class LunchResource extends Resource
{
    protected static ?string $model = Lunch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    // The Lunch planner calendar is the single way in; this list is reached from its "List view" button.
    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return NavGroup::Lunch;
    }

    public static function getModelLabel(): string
    {
        return __('lunch');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lunches');
    }

    public static function form(Schema $schema): Schema
    {
        return LunchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LunchesTable::configure($table);
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
            'index' => ListLunches::route('/'),
            'create' => CreateLunch::route('/create'),
            'edit' => EditLunch::route('/{record}/edit'),
        ];
    }
}
