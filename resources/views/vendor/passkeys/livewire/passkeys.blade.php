<div>
    {{-- Filament's precompiled CSS doesn't carry Spatie's raw Tailwind classes, so this
         restyles the component with Filament Blade components + inline / .dark styling. --}}
    <style>
        .pk-label { display:block; font-size:0.875rem; font-weight:500; margin-bottom:0.375rem; color:#374151; }
        .dark .pk-label { color:#e5e7eb; }
        .pk-error { color:#ef4444; font-size:0.875rem; margin:0.625rem 0 0; }
        .pk-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:0.75rem 1rem; border:1px solid #e5e7eb; border-radius:0.5rem; margin-bottom:0.5rem; background:#fff; }
        .dark .pk-row { border-color:rgb(255 255 255 / 0.1); background:rgb(255 255 255 / 0.05); }
        .pk-name { font-weight:600; color:#111827; }
        .dark .pk-name { color:#f9fafb; }
        .pk-meta { font-size:0.75rem; color:#6b7280; margin-top:0.125rem; }
        .dark .pk-meta { color:#9ca3af; }
        .pk-empty { font-size:0.875rem; color:#6b7280; }
        .dark .pk-empty { color:#9ca3af; }
    </style>

    <form wire:submit="validatePasskeyProperties" style="display:flex;align-items:flex-end;gap:0.75rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:14rem;">
            <label for="passkey-name" class="pk-label">{{ __('passkeys::passkeys.name') }}</label>
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    id="passkey-name"
                    wire:model="name"
                    autocomplete="off"
                    placeholder="{{ __('e.g. My MacBook, iPhone') }}"
                />
            </x-filament::input.wrapper>
        </div>

        <x-filament::button type="submit" icon="heroicon-m-key">
            {{ __('passkeys::passkeys.create') }}
        </x-filament::button>
    </form>

    @error('name')
        <p class="pk-error">{{ $message }}</p>
    @enderror
    {{-- storePasskey() failures are thrown on the 'passkeyForm' error bag. --}}
    @if ($errors->getBag('passkeyForm')->has('name'))
        <p class="pk-error">{{ $errors->getBag('passkeyForm')->first('name') }}</p>
    @endif

    <div style="margin-top:1.5rem;">
        @forelse($passkeys as $passkey)
            <div class="pk-row">
                <div>
                    <div class="pk-name">{{ $passkey->name }}</div>
                    <div class="pk-meta">{{ __('passkeys::passkeys.last_used') }}: {{ $passkey->last_used_at?->diffForHumans() ?? __('passkeys::passkeys.not_used_yet') }}</div>
                </div>
                <x-filament::button color="danger" size="sm" wire:click="deletePasskey({{ $passkey->id }})">
                    {{ __('passkeys::passkeys.delete') }}
                </x-filament::button>
            </div>
        @empty
            <p class="pk-empty">{{ __('No passkeys yet. Add one above for password-free sign-in.') }}</p>
        @endforelse
    </div>
</div>

@include('passkeys::livewire.partials.createScript')
