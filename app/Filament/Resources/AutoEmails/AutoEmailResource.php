<?php

namespace App\Filament\Resources\AutoEmails;

use App\Filament\Resources\AutoEmails\Pages\CreateAutoEmail;
use App\Filament\Resources\AutoEmails\Pages\EditAutoEmail;
use App\Filament\Resources\AutoEmails\Pages\ListAutoEmails;
use App\Filament\Resources\AutoEmails\Schemas\AutoEmailForm;
use App\Filament\Resources\AutoEmails\Tables\AutoEmailsTable;
use App\Models\AutoEmail;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AutoEmailResource extends Resource
{
    protected static ?string $model = AutoEmail::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Welfare & Social';

    protected static ?string $navigationLabel = 'Email Auto Sender';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return AutoEmailForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AutoEmailsTable::configure($table);
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
            'index' => ListAutoEmails::route('/'),
            'create' => CreateAutoEmail::route('/create'),
            'edit' => EditAutoEmail::route('/{record}/edit'),
        ];
    }
}
