<?php

namespace App\Filament\Widgets;

use App\Support\PaymentDetails as Details;
use Filament\Widgets\Widget;

class PaymentDetails extends Widget
{
    // Between the bill totals and the bill history, where a member looks before paying.
    protected static ?int $sort = -4;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.payment-details';

    public static function canView(): bool
    {
        return auth()->check() && ! (bool) auth()->user()?->is_admin;
    }

    public function details(): array
    {
        return Details::for(auth()->user());
    }
}
