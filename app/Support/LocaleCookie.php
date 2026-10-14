<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/** The `locale` cookie: one year, readable by the client-side sync script. */
class LocaleCookie
{
    public static function make(string $code): SymfonyCookie
    {
        return Cookie::make(SetLocale::COOKIE, $code, 60 * 24 * 365, httpOnly: false, sameSite: 'lax');
    }

    public static function queue(string $code): void
    {
        Cookie::queue(self::make($code));
    }
}
