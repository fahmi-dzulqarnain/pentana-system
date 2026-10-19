<?php

namespace App\Filament\Auth;

use App\Providers\AppServiceProvider;
use App\Support\LocaleCookie;
use App\Support\Locales;
use App\Support\Nickname;
use App\Support\TimeFormat;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use SensitiveParameter;

class Profile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // No autofocus: on a phone it pops the keyboard over the page on every visit.
                $this->getNameFormComponent()->autofocus(false),
                TextInput::make('nickname')
                    ->label(__('Nickname'))
                    ->maxLength(30)
                    ->rules(fn (): array => Nickname::rules($this->getUser()))
                    ->dehydrateStateUsing(fn (?string $state) => Nickname::normalise($state))
                    ->helperText(__('What people call you. Shown instead of your full name.')),
                $this->getEmailFormComponent(),
                Select::make('locale')
                    ->label(__('Language'))
                    ->options(fn (): array => collect(Locales::enabled())
                        ->mapWithKeys(fn ($c) => [$c => Locales::nativeName($c)])->all())
                    ->visible(fn (): bool => Locales::multipleEnabled())
                    ->in(fn (): array => Locales::enabled())
                    ->required()
                    ->selectablePlaceholder(false)
                    ->native(false),
                // 'auto' is stored as null, so the clock keeps following the language if it changes.
                Select::make('time_format')
                    ->label(__('Time format'))
                    ->options([
                        'auto' => __('Automatic (follows language)'),
                        TimeFormat::TWELVE => __('12-hour (2:30 PM)'),
                        TimeFormat::TWENTY_FOUR => __('24-hour (14:30)'),
                    ])
                    ->in(['auto', ...TimeFormat::choices()])
                    ->required()
                    ->selectablePlaceholder(false)
                    ->native(false),
                // Read-only, so plain text rendered on the server: the JS date picker showed an
                // empty box until its script formatted the value.
                TextInput::make('birthday')
                    // From the model, not $state: the serialised state is a UTC instant, which for a
                    // date-only value in Malaysia time falls on the previous day.
                    ->formatStateUsing(fn ($record): ?string => $record?->birthday?->translatedFormat(AppServiceProvider::DATE_FORMAT))
                    ->placeholder(__('Not set'))
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(__('Contact an admin to update your birthday.')),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    /**
     * Render the standard profile form (name/email/password from EditProfile) and,
     * below it, the passkeys management section (intro copy + Spatie's
     * register/list component). Mirrors the parent's content() composition rather
     * than round-tripping through it, so the schema's component cache stays clean.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
                ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
                Section::make(__('passkeys::passkeys.passkeys'))
                    ->schema([
                        Text::make(__('Add a passkey for faster, password-free sign-in. Your device (fingerprint, face, or PIN) unlocks the key, and nothing leaves it.')),
                        // Spatie's Livewire register component. Reads auth()->user(); no props.
                        Livewire::make('passkeys'),
                    ]),
            ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // An account that never chose starts on the language it is browsing in, so the
        // now-required field does not block saving the rest of the profile.
        $data['locale'] = Locales::isEnabled($data['locale'] ?? null) ? $data['locale'] : app()->getLocale();
        $data['time_format'] = in_array($data['time_format'] ?? null, TimeFormat::choices(), true) ? $data['time_format'] : 'auto';

        return $data;
    }

    /** `locale` is not mass-assignable; write it explicitly, like every other writer. */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        if (array_key_exists('locale', $data)) {
            $record->forceFill(['locale' => $data['locale']]);
            unset($data['locale']);
        }

        if (array_key_exists('time_format', $data)) {
            $record->forceFill(['time_format' => $data['time_format'] === 'auto' ? null : $data['time_format']]);
            unset($data['time_format']);
        }

        return parent::handleRecordUpdate($record, $data);
    }

    protected function afterSave(): void
    {
        if (Locales::isEnabled($this->getUser()->locale)) {
            LocaleCookie::queue($this->getUser()->locale);
        }
    }
}
