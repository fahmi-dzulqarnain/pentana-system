<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

class Profile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                DatePicker::make('birthday')
                    ->native(false)
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Contact an admin to update your birthday.'),
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
                        Text::make(
                            'Add a passkey for faster, password-free sign-in. Your device '
                            .'(fingerprint, face, or PIN) unlocks the key, and nothing leaves it.'
                        ),
                        // Spatie's Livewire register component. Reads auth()->user(); no props.
                        Livewire::make('passkeys'),
                    ]),
            ]);
    }
}
