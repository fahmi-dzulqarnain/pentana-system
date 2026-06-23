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
         unstyled. --}}
    <link rel="stylesheet" href="{{ asset('css/filament/filament/app.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased" style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;background-color:#f3f4f6;">
    <style>
        .dark body { background-color: #0a0a0a; }
    </style>

    <main style="width:100%;max-width:32rem;">
        {{ $slot }}
    </main>

    @filamentScripts
</body>
</html>
