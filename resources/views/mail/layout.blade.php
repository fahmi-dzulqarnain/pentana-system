{{--
    Branded shell for PENTANA's emails. Table layout and inline styles only, so it renders
    the same in Gmail, Outlook and Apple Mail (none of them honour <style> reliably).

    Sections (all optional): preheader (inbox preview text), hero_bg / hero_bg_image /
    hero_text (hero colours), kicker (small pill above the heading), emoji, heading,
    hero_extra (anything under the heading), content, cta_url + cta_label, after_cta.
    Without a heading there is no hero, so admin-written emails get a plain branded card.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('heading', config('app.name'))</title>
</head>
<body style="margin:0;padding:0;background:#efeae4;font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;-webkit-text-size-adjust:100%;">
    @hasSection('preheader')
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">@yield('preheader')</div>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#efeae4;">
        <tr>
            <td align="center" style="padding:28px 12px 40px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;">
                    <tr>
                        <td style="background:#ffffff;border-radius:24px;overflow:hidden;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                @hasSection('heading')
                                    <tr>
                                        <td bgcolor="@yield('hero_bg', '#1c1917')" style="background:@yield('hero_bg', '#1c1917');background-image:@yield('hero_bg_image', 'none');padding:28px 36px 40px;color:@yield('hero_text', '#ffffff');">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight:800;font-size:13px;letter-spacing:0.22em;color:@yield('hero_text', '#ffffff');">{{ config('app.name') }}</td>
                                                    @hasSection('kicker')
                                                        <td align="right">
                                                            <span style="display:inline-block;padding:5px 12px;border-radius:999px;background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.35);font-size:12px;font-weight:600;color:@yield('hero_text', '#ffffff');">@yield('kicker')</span>
                                                        </td>
                                                    @endif
                                                </tr>
                                            </table>
                                            @hasSection('emoji')
                                                <div style="font-size:56px;line-height:1;margin:32px 0 18px;">@yield('emoji')</div>
                                            @else
                                                <div style="height:32px;"></div>
                                            @endif
                                            <h1 style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:34px;line-height:1.15;font-weight:700;letter-spacing:-0.01em;color:@yield('hero_text', '#ffffff');">@yield('heading')</h1>
                                            @yield('hero_extra')
                                        </td>
                                    </tr>
                                @else
                                    <tr>
                                        <td style="padding:28px 36px 0;font-weight:800;font-size:13px;letter-spacing:0.22em;color:#b45309;">{{ config('app.name') }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:32px 36px 8px;font-size:16px;line-height:1.7;color:#374151;">
                                        @yield('content')
                                    </td>
                                </tr>
                                @hasSection('cta_url')
                                    <tr>
                                        <td style="padding:16px 36px 8px;">
                                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td align="center" bgcolor="@yield('cta_bg', '#1c1917')" style="border-radius:999px;">
                                                        <a href="@yield('cta_url')" target="_blank" style="display:inline-block;padding:15px 30px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">@yield('cta_label') &rarr;</a>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:24px 36px 36px;font-size:15px;line-height:1.6;color:#4b5563;">
                                        @yield('after_cta')
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:22px 8px 0;font-size:12px;line-height:1.6;color:#8a817a;text-align:center;">
                            @hasSection('footer_note')
                                @yield('footer_note')
                            @else
                                {{ __('You are receiving this email because you are a member of :app.', ['app' => config('app.name')]) }}
                            @endif
                            <br>
                            <a href="{{ url('/') }}" style="color:#8a817a;text-decoration:underline;">{{ preg_replace('#^https?://#', '', url('/')) }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
