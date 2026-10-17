@extends('mail.layout')

@php
    $benefits = [
        ['🍱', __('Catered lunches'), __('Vote on the menu and order your meal every lunch day.')],
        ['🎉', __('Activities and outings'), __('Register for events and see who else is going.')],
        ['🤝', __('Welfare fund'), __('Be part of your organisation\'s welfare fund, there for members when it matters.')],
        ['📱', __('Everything in one app'), __('Dues, receipts and reminders on the web, iPhone and iPad.')],
    ];
@endphp

@section('preheader', __('Become a member and enjoy every benefit :app has to offer.', ['app' => config('app.name')]))
@section('hero_bg', '#1c1917')
@section('hero_bg_image', 'radial-gradient(circle at 100% 0%,rgba(245,158,11,0.45) 0%,rgba(28,25,23,0) 55%)')
@section('kicker', __('Membership offer'))
@section('emoji', '💌')
@section('heading', __('Join :app, :name!', ['app' => config('app.name'), 'name' => $name]))

@section('hero_extra')
    <div style="margin-top:14px;font-size:16px;line-height:1.5;color:#fcd34d;">
        {{ __('Become a member and enjoy every benefit :app has to offer.', ['app' => config('app.name')]) }}
    </div>
@endsection

@section('content')
    <p style="margin:0 0 22px;">{{ __('Your organisation has set up a :app membership for you. Here is what it includes and what it costs.', ['app' => config('app.name')]) }}</p>

    {{-- The offer: their fee, then the address they will type on the claim page. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
        <tr>
            <td class="em-ticket" bgcolor="#fffbeb" style="background:#fffbeb;border:2px dashed #d97706;border-radius:16px;padding:20px 22px;">
                <div class="em-ticket-label" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.14em;color:#92400e;margin-bottom:6px;">🎟️ {{ __('Your membership') }}</div>
                @if ($fee)
                    <div class="em-ticket-strong" style="font-family:Georgia,'Times New Roman',serif;font-size:32px;font-weight:700;line-height:1.2;color:#1c1917;">
                        MYR {{ $fee }}<span class="em-ticket-muted" style="font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;font-weight:500;color:#57534e;"> {{ __('/ month') }}</span>
                    </div>
                @endif
                @if ($category)
                    <div class="em-ticket-muted" style="font-size:14px;color:#57534e;margin-top:2px;">{{ __('Member category: :category', ['category' => $category]) }}</div>
                @endif
                <div style="display:inline-block;margin-top:10px;padding:4px 10px;border-radius:999px;background:#1c1917;color:#fcd34d;font-size:12px;font-weight:700;">⏳ {{ __('Offer valid until :date', ['date' => $expires]) }}</div>
                <div style="border-top:1px dashed #f59e0b;margin:14px 0 12px;font-size:0;line-height:0;">&nbsp;</div>
                <div class="em-ticket-muted" style="font-size:12px;color:#78350f;">{{ __('Your account email') }}</div>
                <div class="em-ticket-strong" style="font-size:16px;font-weight:700;color:#1c1917;word-break:break-all;">{{ $email }}</div>
            </td>
        </tr>
    </table>

    <p class="em-strong" style="margin:0 0 14px;font-weight:700;font-size:17px;color:#1c1917;">{{ __('What your membership includes') }}</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
        @foreach ($benefits as [$icon, $title, $text])
            <tr>
                <td valign="top" width="44" style="padding:0 12px 16px 0;">
                    <div class="em-chip" style="width:40px;height:40px;line-height:40px;border-radius:12px;background:#f5f5f4;font-size:20px;text-align:center;">{{ $icon }}</div>
                </td>
                <td valign="top" style="padding:0 0 16px;font-size:15px;line-height:1.5;">
                    <strong class="em-strong" style="color:#1c1917;">{{ $title }}</strong><br>
                    <span class="em-muted" style="color:#57534e;">{{ $text }}</span>
                </td>
            </tr>
        @endforeach
    </table>

    <p class="em-strong" style="margin:0 0 12px;font-weight:700;font-size:17px;color:#1c1917;">{{ __('How to join') }}</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        @foreach ([
            __('Open the claim page and enter the email above.'),
            __('Confirm your date of birth and choose a password.'),
            __('Pay your monthly fee in the app by uploading your payment proof.'),
        ] as $step)
            <tr>
                <td valign="top" style="padding:0 14px 12px 0;">
                    <div class="em-step" style="width:28px;height:28px;line-height:28px;border-radius:14px;background:#1c1917;color:#fcd34d;font-size:13px;font-weight:700;text-align:center;">{{ $loop->iteration }}</div>
                </td>
                <td valign="top" style="padding:3px 0 12px;font-size:15px;">{{ $step }}</td>
            </tr>
        @endforeach
    </table>
@endsection

@section('cta_bg', '#b45309')
@section('cta_url', $url)
@section('cta_label', __('Join now'))

@section('after_cta')
    <p class="em-muted" style="margin:0 0 10px;font-size:13px;color:#57534e;">{{ __('If you do not claim your account by :date, it will be disabled. Your admin can always send you a new invitation.', ['date' => $expires]) }}</p>
    <p class="em-muted" style="margin:0 0 18px;font-size:13px;color:#57534e;">{{ __('Questions about your membership or your fee? Just ask your :app admin.', ['app' => config('app.name')]) }}</p>
    {{ __('Hope to see you inside,') }}<br>
    <strong class="em-strong" style="color:#1c1917;">{{ __('The :app team', ['app' => config('app.name')]) }}</strong>
@endsection

@section('footer_note', __('You are receiving this email because your organisation added you to :app.', ['app' => config('app.name')]))
