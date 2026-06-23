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
    {{-- Full-page (not a centered card): the white background fills the viewport and the
         content flows from the top. A max-width keeps the form readable on wide screens;
         margin auto centres that column horizontally without drawing a boxed card. --}}
    <main style="width:100%;max-width:42rem;margin:0 auto;padding:3rem 1.5rem;box-sizing:border-box;">
        {{ $slot }}
    </main>

    @filamentScripts
</body>
</html>
