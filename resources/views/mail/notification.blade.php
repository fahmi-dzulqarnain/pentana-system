{{-- Every BrandedMail notification: greeting, lines and action in the branded layout. --}}
@extends('mail.layout')

@section('preheader', $introLines[0] ?? '')
@section('hero_bg', $tone['bg'])
@section('hero_bg_image', $tone['gradient'])
@if ($kicker)
    @section('kicker', $kicker)
@endif
@section('emoji', $emoji)
@section('heading', $greeting ?: __('Hello!'))

@section('content')
    @foreach ($introLines as $line)
        <p style="margin:0 0 14px;">{{ $line }}</p>
    @endforeach
@endsection

@isset($actionText)
    @section('cta_bg', $tone['cta'])
    @section('cta_url', $actionUrl)
    @section('cta_label', $actionText)
@endisset

@section('after_cta')
    @foreach ($outroLines as $line)
        <p style="margin:0 0 14px;">{{ $line }}</p>
    @endforeach
    <p style="margin:0 0 18px;">
        {{ __('The :app team', ['app' => config('app.name')]) }}
    </p>
    @isset($actionText)
        <p class="em-muted" style="margin:0;font-size:12px;line-height:1.5;color:#78716c;">
            {{ __('If the button does not work, copy this link into your browser:') }}<br>
            <a class="em-muted" href="{{ $actionUrl }}" style="color:#57534e;word-break:break-all;">{{ $displayableActionUrl }}</a>
        </p>
    @endisset
@endsection
