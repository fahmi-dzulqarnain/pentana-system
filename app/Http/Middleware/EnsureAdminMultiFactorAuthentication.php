<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Stands in for Filament's EnsureMultiFactorAuthenticationIsEnabled. Filament decides once, at
 * route registration, whether MFA is required for the whole panel; this makes it per user:
 * only admins, and only once the grace period is over.
 */
class EnsureAdminMultiFactorAuthentication
{
    public function handle(Request $request, Closure $next): mixed
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        if (! $user->mustSetUpMultiFactorAuthentication()) {
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
