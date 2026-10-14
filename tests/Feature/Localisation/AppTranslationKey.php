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

    /**
     * Install a RecordingTranslator and report every missing app key rendered in $locale
     * into $missing - except a key the translator itself just returned as another key's
     * translation (Filament's translateLabel() re-feeding an already-translated label).
     * Call reset() on the returned translator before each page/component render.
     *
     * @param  array<string, true>  $missing
     */
    protected function guardMissingKeys(string $locale, array &$missing): RecordingTranslator
    {
        $translator = RecordingTranslator::install($this->app);
        $translator->handleMissingKeysUsing(function (string $key, array $replace, ?string $l) use (&$missing, $locale, $translator) {
            if (($l ?? app()->getLocale()) === $locale && self::isAppKey($key) && ! $translator->returned($key)) {
                $missing[$key] = true;
            }

            return $key;
        });

        return $translator;
    }
}
