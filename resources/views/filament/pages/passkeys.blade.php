<x-filament-panels::page>
    <p class="pk-intro">
        Add a passkey for faster, password-free sign-in. Your device (fingerprint, face, or PIN)
        unlocks the key — nothing leaves it.
    </p>

    {{-- Spatie's Livewire register component. Reads auth()->user(); no props. --}}
    <livewire:passkeys />

    {{-- Inline + .dark styling: Filament's precompiled CSS can't carry arbitrary Tailwind/dark: utilities. --}}
    <style>
        .pk-intro { font-size:0.9375rem; color:#4b5563; margin-bottom:0.25rem; }
        .dark .pk-intro { color:#9ca3af; }
    </style>
</x-filament-panels::page>
