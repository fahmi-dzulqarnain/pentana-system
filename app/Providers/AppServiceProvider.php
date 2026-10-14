<?php

namespace App\Providers;

use App\Mail\BudgetedTransport;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Mail;
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
        // Cap outbound mail at Resend's free-tier limits. Wrapping the transport means
        // every sender is covered - notifications, Mail::raw, password resets, Filament -
        // without any of them knowing a cap exists.
        Mail::extend('budgeted', function (array $config) {
            $inner = Mail::mailer($config['inner'] ?? 'smtp')->getSymfonyTransport();

            return new BudgetedTransport($inner);
        });

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
    }
}
