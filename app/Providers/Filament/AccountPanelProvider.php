<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AccountPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('account')
            ->path('account')
            ->login()
            ->databaseNotifications()
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): HtmlString => new HtmlString(
                    '<div style="margin-top:1rem;text-align:center;font-size:0.875rem;">'
                    .'New here? <a href="/claim" style="color:var(--primary-600,#d97706);font-weight:600;">Claim your account</a>'
                    .'</div>'
                ),
            )
            // Spatie's <x-authenticate-passkey> renders a hidden POST form to
            // route('passkeys.login') plus an inline script that runs the WebAuthn
            // ceremony (using the window.startAuthentication global loaded via the
            // passkeys-webauthn Filament asset). On success it logs the user into
            // the web guard and redirects to /account.
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): HtmlString => new HtmlString(
                    '<div style="margin-top:0.75rem;text-align:center;font-size:0.875rem;color:var(--primary-600,#d97706);font-weight:600;">'
                    .Blade::render('<x-authenticate-passkey :redirect="$redirect" />', ['redirect' => '/account'])
                    .'</div>'
                ),
            )
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
