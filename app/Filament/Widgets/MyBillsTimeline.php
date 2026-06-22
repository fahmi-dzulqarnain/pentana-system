<?php

namespace App\Filament\Widgets;

use App\Models\Payment\FeePeriod;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class MyBillsTimeline extends Widget
{
    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.my-bills-timeline';

    protected ?Collection $bills = null;

    /**
     * Members see their bills here; admins use the full Fee Periods resource.
     */
    public static function canView(): bool
    {
        return ! (bool) auth()->user()?->is_admin;
    }

    /**
     * The current member's bills, newest first, with the amount paid pre-summed.
     */
    public function getBills(): Collection
    {
        return $this->bills ??= FeePeriod::forMember(auth()->id())->get();
    }
}
