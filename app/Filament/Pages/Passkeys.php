<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Passkeys extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $navigationLabel = 'Passkeys';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::FingerPrint;

    protected static ?string $title = 'Passkeys';

    protected string $view = 'filament.pages.passkeys';

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
