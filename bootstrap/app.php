<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Served behind a Cloudflare Tunnel: cloudflared reaches nginx over HTTP on
        // localhost and forwards X-Forwarded-Proto=https. Trust it so the app knows
        // requests are HTTPS — required for passkeys (WebAuthn rejects non-HTTPS in
        // production) and for correct https URL/asset generation. nginx listens on
        // 127.0.0.1 only, so the tunnel is the sole path in.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
