<?php

namespace App\Filament\Resources\LunchPlans\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class LunchPlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('date')
                    ->date(),
                TextEntry::make('menu'),
                TextEntry::make('notes')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }
}
