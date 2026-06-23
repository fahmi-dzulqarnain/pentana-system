<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>

    @filamentStyles
    {{-- Filament's compiled component/theme CSS (forms, wizard, inputs, buttons). On a
         panel page @filamentStyles emits this, but this standalone guest route has no
         panel context, so link the published file directly — otherwise the Wizard renders
         unstyled. We deliberately do NOT @vite the app's Tailwind CSS here: it would
         double-load Tailwind preflight on top of Filament's and break the layout.
         Livewire's JS is injected on its own, so no @vite app.js is needed either. --}}
    <link rel="stylesheet" href="{{ asset('css/filament/filament/app.css') }}">
</head>
<body class="antialiased" style="margin:0;min-height:100vh;background-color:#ffffff;">
    {{-- Full-viewport canvas: the white page fills the screen and the claim component
         lays itself out inside (header group at the top, field centred, footer button
         pinned to the bottom). It manages its own padding per stage. --}}
    <main style="width:100%;min-height:100vh;display:flex;flex-direction:column;">
        {{ $slot }}
    </main>

    @filamentScripts
</body>
</html>
