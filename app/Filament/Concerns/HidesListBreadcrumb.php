<?php

namespace App\Filament\Concerns;

/**
 * A list page's breadcrumb ("Members › List") only repeats the page title below it, so it
 * is dropped. Detail pages keep theirs ("Members › View"), which do lead somewhere.
 */
trait HidesListBreadcrumb
{
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
