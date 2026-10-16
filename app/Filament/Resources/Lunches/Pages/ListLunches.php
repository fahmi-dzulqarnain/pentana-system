<?php

namespace App\Filament\Resources\Lunches\Pages;

use App\Filament\Concerns\HidesListBreadcrumb;
use App\Filament\Pages\LunchCalendar;
use App\Filament\Resources\Lunches\LunchResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLunches extends ListRecords
{
    use HidesListBreadcrumb;

    protected static string $resource = LunchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendarView')->label(__('Calendar view'))->icon('heroicon-o-calendar-days')->color('gray')
                ->url(fn () => LunchCalendar::getUrl()),
            CreateAction::make(),
        ];
    }
}
