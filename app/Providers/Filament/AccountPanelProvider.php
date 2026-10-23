<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Auth\Profile;
use App\Http\Middleware\EnsureAdminMultiFactorAuthentication;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\LocaleCookie;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\UserMenuPosition;
use Filament\Forms\Components\Radio;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
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
            ->login(Login::class)
            ->profile(Profile::class, isSimple: false)
            // Required panel-wide so Filament registers the set-up page; the middleware narrows it
            // to admins past config('auth.admin_mfa_enforced_from'). Passkey sign-in skips the
            // challenge: a passkey is already a second factor.
            ->multiFactorAuthentication(
                AppAuthentication::make()->recoverable()->brandName('PENTANA'),
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureAdminMultiFactorAuthentication::class)
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => self::adminMultiFactorReminder())
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => auth()->user()?->shouldBeOfferedAPasskey() && ! request()->routeIs('filament.account.pages.welcome', 'filament.account.auth.profile')
                ? Blade::render('<livewire:passkey-nudge />')
                : '')
            ->userMenu(position: UserMenuPosition::Sidebar)
            ->databaseNotifications()
            // English-only (production today) renders none of these, so the pages stay byte-for-byte as before.
            ->renderHook(PanelsRenderHook::HEAD_START, fn (): string => Locales::multipleEnabled() ? Blade::render('<x-locale.sync-script />') : '')
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => Locales::multipleEnabled() ? Blade::render('<x-locale.chooser />') : '')
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn (): string => Locales::multipleEnabled()
                ? Blade::render('<div class="account-login-locale-switcher" style="display:flex;justify-content:center;margin-bottom:1rem"><x-locale.switcher /></div>')
                : '')
            ->userMenuItems([
                Action::make('language')
                    ->label(fn (): string => __('Language'))
                    ->icon('heroicon-o-language')
                    ->visible(fn (): bool => Locales::multipleEnabled())
                    ->schema([
                        Radio::make('locale')
                            ->hiddenLabel()
                            ->options(fn (): array => collect(Locales::enabled())
                                ->mapWithKeys(fn ($c) => [$c => Locales::nativeName($c)])->all())
                            ->default(fn (): string => app()->getLocale())
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        if (! Locales::isEnabled($data['locale'] ?? null)) {
                            return;
                        }
                        auth()->user()->forceFill(['locale' => $data['locale']])->save();
                        LocaleCookie::queue($data['locale']);

                        return redirect(request()->header('Referer') ?? '/account');
                    }),
            ])
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): HtmlString => new HtmlString(
                    '<div style="margin-top:1rem;text-align:center;font-size:0.875rem;">'
                    .e(__('New here?')).' <a href="/claim" style="color:var(--primary-600,#d97706);font-weight:600;">'.e(__('Claim your account')).'</a>'
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
                    Blade::render(
                        <<<'BLADE'
                        <div style="margin-top:1rem;">
                            <x-authenticate-passkey :redirect="$redirect">
                                <x-filament::button
                                    type="button"
                                    outlined
                                    color="gray"
                                    icon="heroicon-o-finger-print"
                                    style="width:100%;justify-content:center;"
                                >
                                    {{ __('passkeys::passkeys.authenticate_using_passkey') }}
                                </x-filament::button>
                            </x-authenticate-passkey>
                        </div>
                        BLADE,
                        ['redirect' => '/account'],
                    )
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
            // No AccountWidget: its greeting and sign-out button repeat the user menu.
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetLocale::class,
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

    /** During the grace period, tells an admin without an authenticator app when it becomes required. */
    private static function adminMultiFactorReminder(): string
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->shouldBeRemindedAboutMultiFactorAuthentication()) {
            return '';
        }

        $enforcedFrom = User::adminMultiFactorAuthenticationEnforcedFrom();

        return Blade::render(<<<'BLADE'
            <x-filament::callout icon="heroicon-o-shield-exclamation" color="warning" :heading="__('Set up two-factor authentication')"
                :description="__('Admin accounts need an authenticator app from :date. Set it up now so you are not locked out.', ['date' => $date])">
                <x-slot name="footer">
                    <x-filament::button tag="a" :href="$url" size="sm">{{ __('Set up now') }}</x-filament::button>
                </x-slot>
            </x-filament::callout>
            BLADE, [
            'date' => $enforcedFrom->translatedFormat('j F Y'),
            'url' => filament()->getProfileUrl(),
        ]);
    }
}
