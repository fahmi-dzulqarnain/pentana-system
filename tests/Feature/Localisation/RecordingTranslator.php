<?php

namespace Tests\Feature\Localisation;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\Translator;

/**
 * Records every string the translator returned from a successful lookup of a different key.
 *
 * Filament's global translateLabel() (see AppServiceProvider) passes labels that are already
 * translated (e.g. ->label(__('Language')) = "Bahasa") through __() a second time, and that
 * second lookup is reported as missing. The runtime guards skip a missing key only when it is
 * one of these recorded results from the same render, so a real untranslated label is still
 * reported even when it happens to equal some vendor or JSON value.
 */
class RecordingTranslator extends Translator
{
    /** @var array<string, true> translated results returned since the last reset() */
    public array $results = [];

    private bool $missed = false;

    /** Swap the app's translator for a recording one (keeps loader, locale and fallback). */
    public static function install(Application $app): self
    {
        $app->extend('translator', function (Translator $translator): self {
            if ($translator instanceof self) {
                return $translator;
            }

            $recording = new self($translator->getLoader(), $translator->getLocale());
            $recording->setFallback($translator->getFallback());

            return $recording;
        });
        Facade::clearResolvedInstance('translator');

        return $app->make('translator');
    }

    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $this->missed = false;
        $line = parent::get($key, $replace, $locale, $fallback);

        if (! $this->missed && is_string($line) && $line !== $key) {
            $this->results[$line] = true;
        }

        return $line;
    }

    protected function handleMissingTranslationKey($key, $replace, $locale, $fallback)
    {
        $this->missed = true;

        return parent::handleMissingTranslationKey($key, $replace, $locale, $fallback);
    }

    public function reset(): void
    {
        $this->results = [];
    }

    /** True when $key was itself returned as the translation of another key since the last reset(). */
    public function returned(string $key): bool
    {
        return isset($this->results[$key]);
    }
}
