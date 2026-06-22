<?php

namespace App\Filament\Resources\MemberCategories;

use App\Filament\Resources\MemberCategories\Pages\ListMemberCategories;
use App\Filament\Resources\MemberCategories\Schemas\MemberCategoryForm;
use App\Filament\Resources\MemberCategories\Tables\MemberCategoriesTable;
use App\Models\MemberCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MemberCategoryResource extends Resource
{
    protected static ?string $model = MemberCategory::class;

    protected static ?string $recordTitleAttribute = 'Member Category';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Tag;

    protected static string|UnitEnum|null $navigationGroup = 'Accounts';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return MemberCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MemberCategoriesTable::configure($table);
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
            'index' => ListMemberCategories::route('/'),
        ];
    }
}
