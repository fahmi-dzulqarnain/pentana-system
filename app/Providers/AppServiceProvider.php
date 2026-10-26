<?php

namespace App\Providers;

use App\Http\Middleware\SetLocale;
use App\Mail\BudgetedTransport;
use App\Models\DeviceToken;
use App\Models\User;
use App\Support\TimeFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Schema;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use NotificationChannels\Apn\ApnChannel;

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
            // Same reasons; backs the password meter on the claim and profile pages.
            Js::make('password-strength', Vite::asset('resources/js/password-strength.js'))->module(),
        ]);

        // NIST SP 800-63B: length plus a breach check, no forced digits or symbols (they push
        // people to "Pentana2026!"). Only the first 5 characters of the SHA-1 hash leave the
        // server, and an unreachable Have I Been Pwned lets the password through. Every
        // Password::defaults() caller gets this: the claim wizard and the profile's new password.
        Password::defaults(fn () => Password::min(self::PASSWORD_MIN_LENGTH)->uncompromised());

        // Safeguard: Livewire's update route already runs the `web` group (which appends
        // SetLocale), but persistent middleware also re-applies it to routes that get it
        // only from a custom group, so a re-render never falls back to English mid-form.
        Livewire::addPersistentMiddleware([SetLocale::class]);

        // Route Filament's auto-generated labels ("Send at") through __() so they can
        // be translated in lang/*.json like any explicit label.
        foreach ([
            Field::class,
            Column::class,
            BaseFilter::class,
            Entry::class,
        ] as $component) {
            $component::configureUsing(fn ($c) => $c->translateLabel());
        }

        // Money always renders "MYR 70.00": without a pinned number locale Filament's
        // ->money('MYR') follows the app locale and prints "RM 70.00" (ms) / "MYR 70,00" (id).
        Table::configureUsing(fn ($table) => $table->defaultNumberLocale('en'));
        // Phones get each row as a labelled card instead of a table that scrolls sideways.
        Table::configureUsing(fn ($table) => $table->stackedOnMobile());
        Schema::configureUsing(fn ($schema) => $schema->defaultNumberLocale('en'));

        // One date style everywhere, day first as people write it here: "16 Okt 2026, 10:42".
        foreach ([Table::class, Schema::class] as $container) {
            $container::configureUsing(fn ($c) => $c
                ->defaultDateDisplayFormat(self::DATE_FORMAT)
                ->defaultDateTimeDisplayFormat(fn (): string => self::dateTimeFormat()));
        }
        DatePicker::configureUsing(fn (DatePicker $picker) => $picker->displayFormat(self::DATE_FORMAT));
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker->displayFormat(fn (): string => self::dateTimeFormat()));

        // Each notification is rendered once per recipient (inside their language); render its
        // times in their clock too, then fall back to the signed-in user.
        Event::listen(NotificationSending::class, fn (NotificationSending $e) => TimeFormat::useRecipient($e->notifiable instanceof User ? $e->notifiable : null));
        Event::listen(NotificationSent::class, fn () => TimeFormat::useRecipient(null));
        Event::listen(NotificationFailed::class, fn () => TimeFormat::useRecipient(null));

        // A token APNs says is dead (app deleted, push turned off) would otherwise keep the
        // member off email for good, since routine notices go push-only to token holders.
        Event::listen(NotificationFailed::class, function (NotificationFailed $e): void {
            if ($e->channel === ApnChannel::class && in_array($e->data['error'] ?? null, self::DEAD_APN_TOKEN_ERRORS, true)) {
                DeviceToken::where('token', $e->data['token'])->delete();
            }
        });

        // FilePond has no Malay locale, so its English idle prompt leaked into the ms UI.
        // Resolved per render (closure) so it follows the viewer's language.
        FileUpload::configureUsing(fn (FileUpload $upload) => $upload->placeholder(fn (): string => __('Drag & drop your file or :browse', [
            'browse' => '<span class="filepond--label-action">'.__('Browse').'</span>',
        ])));

        // An empty list is a normal starting state, not an error: a neutral icon and "No … yet"
        // instead of Filament's grey ✕ and "No records found".
        Table::configureUsing(fn (Table $table) => $table
            ->emptyStateIcon('heroicon-o-inbox')
            ->emptyStateHeading(fn (Table $table): string => __('No :items yet', ['items' => $table->getPluralModelLabel()])));
    }

    public const PASSWORD_MIN_LENGTH = 12;

    /** APNs reasons that mean the token will never deliver again. */
    public const DEAD_APN_TOKEN_ERRORS = ['Unregistered', 'BadDeviceToken', 'DeviceTokenNotForTopic'];

    public const DATE_FORMAT = 'j M Y';

    /** "16 Okt 2026, 2:30 PM" or "16 Okt 2026, 14:30", following the viewer's clock. */
    public static function dateTimeFormat(): string
    {
        return self::DATE_FORMAT.', '.TimeFormat::time();
    }
}
