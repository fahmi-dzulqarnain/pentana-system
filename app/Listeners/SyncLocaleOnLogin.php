<?php

namespace App\Listeners;

use App\Http\Middleware\SetLocale;
use App\Support\LocaleCookie;
use App\Support\Locales;
use Illuminate\Auth\Events\Login;

/**
 * On sign-in the account's language wins and is pushed to this device's cookie
 * (the page's sync script then updates localStorage). An account without one
 * adopts the language this device already chose.
 */
class SyncLocaleOnLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;
        $device = request()->cookie(SetLocale::COOKIE);

        if (Locales::isEnabled($user->locale ?? null)) {
            LocaleCookie::queue($user->locale);
        } elseif (Locales::isEnabled($device)) {
            $user->forceFill(['locale' => $device])->save();
        }
    }
}
