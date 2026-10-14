<?php

namespace App\Filament\Auth;

use App\Support\LocaleCookie;
use App\Support\Locales;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
                $this->getNameFormComponent(),
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
                DatePicker::make('birthday')
                    ->native(false)
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

        return $data;
    }

    /** `locale` is not mass-assignable; write it explicitly, like every other writer. */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        if (array_key_exists('locale', $data)) {
            $record->forceFill(['locale' => $data['locale']]);
            unset($data['locale']);
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
