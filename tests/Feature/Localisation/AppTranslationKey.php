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
     * or in our own lang/{$locale}.json. Filament's translateLabel() (see AppServiceProvider)
     * re-feeds labels that are already translated - by the vendor (e.g. "Kata laluan") or
     * by an explicit ->label(__('Language')) ("Bahasa") - through __(), which then reports
     * them as missing; they are not untranslated strings, so the runtime guard ignores them.
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
            $json = lang_path("$locale.json");
            foreach (is_file($json) ? json_decode(file_get_contents($json), true, flags: JSON_THROW_ON_ERROR) : [] as $v) {
                $values[$v] = true;
            }
            self::$vendorValues[$locale] = $values;
        }

        return isset(self::$vendorValues[$locale][$key]);
    }
}
