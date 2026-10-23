<?php

namespace App\Livewire;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\View\View;
use Spatie\LaravelPasskeys\Livewire\PasskeysComponent;

/**
 * Spatie's passkey register flow with one big button: the device name is filled in from the
 * browser so there's nothing to type, and a saved passkey moves on to the dashboard.
 */
class PasskeyWelcome extends PasskeysComponent
{
    public function mount(): void
    {
        $this->name = self::deviceName((string) request()->userAgent());
    }

    public function render(): View
    {
        return view('livewire.passkey-welcome', ['dashboardUrl' => Filament::getUrl()]);
    }

    public function storePasskey(string $passkey): void
    {
        parent::storePasskey($passkey);

        Notification::make()->title(__('Passkey added. Next time, sign in with it instead of your password.'))->success()->send();

        $this->redirect(Filament::getUrl(), navigate: true);
    }

    /** A readable name for the passkey list on the profile ("iPhone", "Mac"). */
    public static function deviceName(string $userAgent): string
    {
        foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Macintosh' => 'Mac', 'Windows' => 'Windows', 'CrOS' => 'Chromebook', 'Linux' => 'Linux'] as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        return __('This device');
    }
}
