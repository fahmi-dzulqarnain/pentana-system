<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Lunch') }}: {{ $lunch->date->translatedFormat('l, j F') }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #111; margin: 24px; font-size: 14px; line-height: 1.5; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 20px 0 4px; }
        h3 { font-size: 14px; margin: 12px 0 4px; }
        .summary { color: #444; margin: 0 0 8px; }
        ul { list-style: none; margin: 0; padding: 0; columns: 2; }
        li { break-inside: avoid; padding: 2px 0; }
        .box { display: inline-block; width: 1.2em; }
        .tag, .none { color: #666; font-style: italic; }
        .print-btn { margin-bottom: 16px; padding: 6px 14px; font: inherit; cursor: pointer; }
        @media print {
            body { margin: 0; }
            .print-btn { display: none; }
            h2, h3 { break-after: avoid; }
        }
    </style>
</head>
<body>
    <button type="button" class="print-btn" onclick="window.print()">{{ __('Print') }}</button>

    <h1>{{ __('Lunch') }}: {{ $lunch->date->translatedFormat('l, j F') }}</h1>
    <p class="summary">
        {{ __(':ordering ordering · :not_attending not attending · :not_responded not responded · printed :time', [
            'ordering' => $breakdown['counts']['ordering'],
            'not_attending' => $breakdown['counts']['not_attending'],
            'not_responded' => $breakdown['counts']['not_responded'],
            'time' => now()->translatedFormat('j M Y, H:i'),
        ]) }}
    </p>

    @foreach ($breakdown['menus'] as $menu)
        <h2>{{ $menu['meal'] }}@if ($menu['caterer']) ({{ $menu['caterer'] }})@endif</h2>
        @foreach ($menu['options'] as $option)
            <h3>{{ $option['name'] }}: {{ count($option['members']) }}</h3>
            @include('lunches.print-members', ['list' => $option['members'], 'tick' => true])
        @endforeach
    @endforeach

    <h2>{{ __('Not attending: :count', ['count' => $breakdown['counts']['not_attending']]) }}</h2>
    @include('lunches.print-members', ['list' => $breakdown['not_attending'], 'tick' => false])

    <h2>{{ __('Not responded: :count', ['count' => $breakdown['counts']['not_responded']]) }}</h2>
    @include('lunches.print-members', ['list' => $breakdown['not_responded'], 'tick' => false])
</body>
</html>
