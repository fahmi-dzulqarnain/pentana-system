<?php

namespace App\Models\Contracts;

/** A record lunch days point at, which must not be deleted while any of them still do. */
interface UsedByLunchDays
{
    /** How many lunch days use this record. */
    public function lunchDaysCount(): int;
}
