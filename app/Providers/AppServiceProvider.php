<?php

namespace App\Providers;

use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load the WebAuthn globals (startAuthentication / startRegistration) on
        // Filament panel pages. Filament does not load resources/js/app.js, and
        // Spatie's passkey components rely on those bare globals for the login
        // page and the Passkeys register page. We register a dedicated, minimal
        // resources/js/passkeys.js (no axios/Alpine/Livewire) as a Filament asset
        // so it ships on every panel page without conflicting with Filament's
        // bundled Alpine/Livewire.
        //
        // ->module() is REQUIRED: Vite builds passkeys.js as an ES module that
        // `import`s a code-split @simplewebauthn/browser chunk. Without type="module"
        // the browser rejects the import statement, the file never runs, and the
        // globals stay undefined ("startAuthentication is not defined").
        FilamentAsset::register([
            Js::make('passkeys-webauthn', Vite::asset('resources/js/passkeys.js'))->module(),
        ]);

        // Safeguard: Livewire's update route already runs the `web` group (which appends
        // SetLocale), but persistent middleware also re-applies it to routes that get it
        // only from a custom group, so a re-render never falls back to English mid-form.
        \Livewire\Livewire::addPersistentMiddleware([\App\Http\Middleware\SetLocale::class]);
    }
}
