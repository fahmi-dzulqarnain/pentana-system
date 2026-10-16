@extends('mail.layout')

@section('preheader', __('Wishing you a wonderful year ahead from all of us at :app.', ['app' => config('app.name')]))
@section('hero_bg', '#c2410c')
@section('hero_bg_image', 'linear-gradient(135deg,#c2410c 0%,#b91c1c 50%,#9d174d 100%)')
@section('kicker', now()->translatedFormat('j F'))
@section('emoji', '🎂')
@section('heading', __('Happy birthday, :name!', ['name' => $name]))

@section('hero_extra')
    <div style="margin-top:14px;font-size:16px;line-height:1.5;color:#ffffff;">
        {{ __('Wishing you a wonderful year ahead from all of us at :app.', ['app' => config('app.name')]) }}
    </div>
    <div style="margin-top:22px;font-size:22px;letter-spacing:10px;line-height:1;">🎈✨🎁✨🎉</div>
@endsection

@section('content')
    <p style="margin:0 0 16px;">{{ __('Today is all about you. Everyone at :app is wishing you a day full of laughter, good food and the people you love.', ['app' => config('app.name')]) }}</p>
    <p style="margin:0 0 16px;">{{ __('Thank you for being part of our community. The lunches, the activities and the moments we share are better because you are in them.') }}</p>
    <p style="margin:0;">{{ __('May the year ahead bring you good health, happiness and plenty of reasons to celebrate.') }}</p>
@endsection

@section('cta_bg', '#be185d')
@section('cta_url', $url)
@section('cta_label', __('Visit :app', ['app' => config('app.name')]))

@section('after_cta')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td style="background:#fff7ed;border-radius:16px;padding:18px 22px;font-family:Georgia,'Times New Roman',serif;font-style:italic;font-size:17px;line-height:1.5;color:#9a3412;">
                {{ __('Warm wishes,') }}<br>
                <span style="font-style:normal;font-weight:700;">{{ __('The :app team', ['app' => config('app.name')]) }}</span>
            </td>
        </tr>
    </table>
@endsection
