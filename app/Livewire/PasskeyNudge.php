<?php

namespace App\Livewire;

use App\Filament\Auth\Profile;
use Illuminate\View\View;
use Livewire\Component;

/** The "add a passkey" banner at the top of /account until the member adds one or dismisses it. */
class PasskeyNudge extends Component
{
    public bool $dismissed = false;

    public function dismiss(): void
    {
        auth()->user()->forceFill(['passkey_prompt_dismissed_at' => now()])->save();
        $this->dismissed = true;
    }

    public function render(): View
    {
        return view('livewire.passkey-nudge', ['profileUrl' => Profile::getUrl(panel: 'account')]);
    }
}
