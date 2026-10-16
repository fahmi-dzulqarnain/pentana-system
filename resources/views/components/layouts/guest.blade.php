<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <x-locale.sync-script />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>

    @filamentStyles
    {{-- Filament's compiled component/theme CSS (forms, wizard, inputs, buttons). On a
         panel page @filamentStyles emits this, but this standalone guest route has no
         panel context, so link the published file directly - otherwise the Wizard renders
         unstyled. We deliberately do NOT @vite the app's Tailwind CSS here: it would
         double-load Tailwind preflight on top of Filament's and break the layout.
         Livewire's JS is injected on its own, so no @vite app.js is needed either. --}}
    <link rel="stylesheet" href="{{ asset('css/filament/filament/app.css') }}">
</head>
<body class="antialiased" style="margin:0;min-height:100vh;background-color:#ffffff;">
    {{-- Brand header: a page that asks for an email should say whose page it is and offer a
         way back home, not float a lone globe icon in the corner. --}}
    <header class="guest-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:0.875rem 1.5rem;border-bottom:1px solid #e5e7eb;">
        <a href="/" aria-label="{{ __('PENTANA home') }}" style="display:inline-flex;align-items:center;gap:0.5rem;text-decoration:none;color:#111827;font-weight:700;letter-spacing:0.12em;">
            <img src="{{ asset('images/pentana-mark.png') }}" alt="" width="28" height="28" style="display:block;">
            <span>PENTANA</span>
        </a>
        @if (\App\Support\Locales::multipleEnabled())
            <div class="guest-locale-switcher"><x-locale.switcher /></div>
        @endif
    </header>
    {{-- Full-viewport canvas: the white page fills the screen and the claim component
         lays itself out inside (header group at the top, field centred, footer button
         pinned to the bottom). It manages its own padding per stage. --}}
    <main style="width:100%;min-height:calc(100vh - 3.75rem);display:flex;flex-direction:column;">
        {{ $slot }}
    </main>

    @filamentScripts
    <x-locale.chooser />
</body>
</html>
