<?php

namespace App\Filament\Widgets;

use App\Models\Activity;
use Filament\Widgets\Widget;

class MyNextActivity extends Widget
{
    protected static ?int $sort = -3;

    protected string $view = 'filament.widgets.my-next-activity';

    public static function canView(): bool
    {
        return auth()->check() && ! (bool) auth()->user()?->is_admin;
    }

    /** The member's soonest upcoming activity they hold a confirmed seat for. */
    public function nextActivity(): ?Activity
    {
        return Activity::query()
            ->where('status', 'published')
            ->upcoming()
            ->whereHas('registrations', fn ($query) => $query
                ->where('user_id', auth()->id())
                ->where('status', 'registered'))
            ->first();
    }
}
