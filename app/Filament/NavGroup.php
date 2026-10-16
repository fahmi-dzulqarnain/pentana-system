<?php

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * Sidebar groups, in display order. A member's own pages come first; the admin tools
 * follow, grouped by what they manage. Labels resolve per request, so they follow the
 * viewer's language.
 */
enum NavGroup implements HasLabel
{
    case Personal;
    case Lunch;
    case Activities;
    case Finance;
    case Members;

    public function getLabel(): string
    {
        return match ($this) {
            self::Personal => __('Personal'),
            self::Lunch => __('Lunch'),
            self::Activities => __('Activities & emails'),
            self::Finance => __('Finance'),
            self::Members => __('Members'),
        };
    }
}
