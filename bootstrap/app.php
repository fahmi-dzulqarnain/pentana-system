<?php

use App\Http\Middleware\SetLocale;
use Filament\Facades\Filament;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Served behind a Cloudflare Tunnel: cloudflared reaches nginx over HTTP on
        // localhost and forwards X-Forwarded-Proto=https. Trust it so the app knows
        // requests are HTTPS - required for passkeys (WebAuthn rejects non-HTTPS in
        // production) and for correct https URL/asset generation. nginx listens on
        // 127.0.0.1 only, so the tunnel is the sole path in.
        $middleware->trustProxies(at: '*');

        // There is no app-level login route; send guests to the account panel's.
        $middleware->redirectGuestsTo(fn () => Filament::getPanel('account')->getLoginUrl());

        // The locale cookie is read by the client-side sync script, so it must stay plain.
        $middleware->encryptCookies(except: [SetLocale::COOKIE]);
        $middleware->web(append: [SetLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
