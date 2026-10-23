<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Where a member lands right after claiming their account: one offer to add a passkey, so most
 * members never type their password again. Not in the navigation; skipping it goes to the dashboard.
 */
class Welcome extends Page
{
    protected static ?string $slug = 'welcome';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.welcome';

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->passkeys()->exists()) {
            $this->redirect(Filament::getUrl(), navigate: true);
        }
    }

    public function getTitle(): string
    {
        return __('Sign in faster next time');
    }
}
