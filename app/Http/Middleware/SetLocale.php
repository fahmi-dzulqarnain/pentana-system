<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale for web, panel and Livewire requests: the signed-in account's choice,
 * else the `locale` cookie, else the default. Anything not enabled in
 * APP_LOCALES is ignored, so disabling a language safely re-prompts its users.
 */
class SetLocale
{
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        // The cookie is unencrypted, so it can arrive array-shaped (`locale[x]=1`); only strings count.
        $cookie = $request->cookie(self::COOKIE);
        $choice = collect([$request->user()?->locale, is_string($cookie) ? $cookie : null])
            ->first(fn (?string $code) => Locales::isEnabled($code));

        $locale = $choice ?? config('app.locale');

        App::setLocale($locale);
        Carbon::setLocale($locale);
        View::share('localeChosen', $choice !== null);

        return $next($request);
    }
}
