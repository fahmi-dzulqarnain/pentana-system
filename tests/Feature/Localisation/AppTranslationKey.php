<?php

namespace Tests\Feature\Localisation;

/** The single definition of "an app translation key" shared by the localisation guards. */
trait AppTranslationKey
{
    /** Vendor-namespaced (pkg::file.key) and PHP group keys (file.key) are not ours. */
    protected static function isAppKey(string $key): bool
    {
        return ! str_contains($key, '::') && ! preg_match('/^[a-z_]+\.[a-z0-9_.]+$/', $key);
    }
}
