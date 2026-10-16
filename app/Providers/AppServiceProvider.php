<?php

namespace App\Providers;

use App\Http\Middleware\SetLocale;
use App\Mail\BudgetedTransport;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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
                ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT));
        }
        DatePicker::configureUsing(fn (DatePicker $picker) => $picker->displayFormat(self::DATE_FORMAT));
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker->displayFormat(self::DATE_TIME_FORMAT));

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

    public const DATE_FORMAT = 'j M Y';

    public const DATE_TIME_FORMAT = 'j M Y, H:i';
}
