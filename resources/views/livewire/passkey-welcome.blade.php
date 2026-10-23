{{-- Browsers without WebAuthn can't use a passkey, so they skip straight to the dashboard. --}}
<div x-data x-init="window.PublicKeyCredential || (window.location.href = @js($dashboardUrl))" style="max-width:32rem;margin:0 auto;">
    <style>
        .pk-welcome-copy { margin: 0; color: var(--gray-600); }
        .dark .pk-welcome-copy { color: var(--gray-300); }
    </style>
    <x-filament::section>
        <div style="display:flex;flex-direction:column;align-items:center;text-align:center;gap:1rem;padding:1rem 0;">
            <x-filament::icon icon="heroicon-o-finger-print" style="width:3rem;height:3rem;color:var(--primary-500);" />
            <p class="pk-welcome-copy">
                {{ __('Your account is ready. Add a passkey and next time you sign in with your fingerprint, face or device PIN, no password needed.') }}
            </p>

            <form wire:submit="validatePasskeyProperties" style="width:100%;">
                <x-filament::button type="submit" size="xl" icon="heroicon-m-key" style="width:100%;justify-content:center;">
                    {{ __('Add a passkey') }}
                </x-filament::button>
            </form>

            @if ($errors->getBag('passkeyForm')->has('name') || $errors->has('name'))
                <p style="margin:0;color:var(--danger-600);font-size:0.875rem;">{{ $errors->getBag('passkeyForm')->first('name') ?: $errors->first('name') }}</p>
            @endif

            <x-filament::link :href="$dashboardUrl" color="gray">{{ __('Maybe later') }}</x-filament::link>
        </div>
    </x-filament::section>
</div>

@include('passkeys::livewire.partials.createScript')
