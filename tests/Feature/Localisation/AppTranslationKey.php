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

    /** @var array<string, array<string, true>> */
    private static array $vendorValues = [];

    /**
     * True when $key is itself a value in Filament's / laravel-lang's PHP translations
     * for $locale. Filament's translateLabel() (see AppServiceProvider) re-feeds labels
     * it already translated (e.g. "Kata laluan") through __(), which then reports them
     * as missing; they are not app strings, so the runtime guard must ignore them.
     */
    protected static function isVendorTranslatedValue(string $key, string $locale): bool
    {
        if (! isset(self::$vendorValues[$locale])) {
            $files = array_merge(
                glob(base_path("vendor/filament/*/resources/lang/$locale/{,*/,*/*/}*.php"), GLOB_BRACE) ?: [],
                glob(lang_path("$locale/*.php")) ?: [],
            );
            $values = [];
            foreach ($files as $file) {
                $array = require $file;
                if (is_array($array)) {
                    array_walk_recursive($array, function ($v) use (&$values) {
                        if (is_string($v)) {
                            $values[$v] = true;
                        }
                    });
                }
            }
            self::$vendorValues[$locale] = $values;
        }

        return isset(self::$vendorValues[$locale][$key]);
    }
}
