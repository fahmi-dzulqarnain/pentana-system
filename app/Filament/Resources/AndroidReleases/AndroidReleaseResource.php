<?php

namespace App\Filament\Resources\AndroidReleases;

use App\Filament\NavGroup;
use App\Filament\Resources\AndroidReleases\Pages\CreateAndroidRelease;
use App\Filament\Resources\AndroidReleases\Pages\ListAndroidReleases;
use App\Filament\Resources\AndroidReleases\Schemas\AndroidReleaseForm;
use App\Filament\Resources\AndroidReleases\Tables\AndroidReleasesTable;
use App\Models\AndroidRelease;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Admins publish sideloadable APKs here; the landing page always offers the newest one. */
class AndroidReleaseResource extends Resource
{
    protected static ?string $model = AndroidRelease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DevicePhoneMobile;

    protected static ?int $navigationSort = 9;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return NavGroup::Members;
    }

    public static function getNavigationLabel(): string
    {
        return __('Android releases');
    }

    public static function getModelLabel(): string
    {
        return __('Android release');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Android releases');
    }

    public static function form(Schema $schema): Schema
    {
        return AndroidReleaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AndroidReleasesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAndroidReleases::route('/'),
            'create' => CreateAndroidRelease::route('/create'),
        ];
    }
}
