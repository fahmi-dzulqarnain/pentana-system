<?php

namespace App\Filament\Resources\Lunches;

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

    protected static string|UnitEnum|null $navigationGroup = 'Welfare & Social';

    protected static ?string $navigationLabel = 'Lunch Planner';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
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
