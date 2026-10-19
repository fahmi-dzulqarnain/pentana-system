<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <x-locale.sync-script />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Install on Android') }} | PENTANA</title>
    <meta name="description" content="{{ __('Step-by-step guide to installing the PENTANA app on an Android phone.') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/pentana-mark.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400&display=swap" rel="stylesheet">
    @verbatim
    <style>
        :root { --ink:#211a14; --muted:#50453b; --bg:#fff8f4; --card:#ffffff; --line:#e5ddd4; --orange:#9a4a00; --navy:#1b1464; --btn:#1b1464; --btn-ink:#ffffff; --tip:#fff1e3; }
        @media (prefers-color-scheme: dark) {
            :root { --ink:#efe0d5; --muted:#b8ab9e; --bg:#141019; --card:#1d1826; --line:#2c2740; --orange:#ffb784; --navy:#c8bfff; --btn:#c8bfff; --btn-ink:#141019; --tip:#2a2030; }
        }
        * { box-sizing: border-box; }
        body { margin:0; font-family:'IBM Plex Sans', system-ui, -apple-system, sans-serif; color:var(--ink); background:var(--bg); line-height:1.6; }
        .w { max-width:740px; margin:0 auto; padding:0 20px; }
        header { border-bottom:1px solid var(--line); }
        header .w { padding:16px 20px; }
        header .w.has-locale { display:flex; align-items:center; justify-content:space-between; gap:12px; }
        header .ls { --border-subtle:var(--line); }
        .brand { font-weight:800; letter-spacing:.12em; color:var(--navy); text-decoration:none; font-size:18px; }
        main { padding:40px 20px 64px; }
        h1 { font-size:32px; line-height:1.2; color:var(--navy); margin:0 0 8px; }
        .lede { color:var(--muted); margin:0 0 24px; }
        h2 { font-size:20px; color:var(--navy); margin:40px 0 8px; }
        p, li { margin:8px 0; }
        a { color:var(--orange); }
        ul { padding-left:22px; }
        .get { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:20px; display:flex; flex-wrap:wrap; align-items:center; gap:12px 20px; }
        .get .btn { display:inline-flex; align-items:center; gap:10px; background:var(--btn); color:var(--btn-ink); font-weight:700; text-decoration:none; padding:12px 20px; border-radius:12px; }
        .get .meta { color:var(--muted); font-size:14px; margin:0; }
        .steps { list-style:none; padding:0; margin:24px 0 0; counter-reset:step; }
        .steps > li { counter-increment:step; position:relative; padding:0 0 0 52px; margin:0 0 28px; }
        .steps > li::before { content:counter(step); position:absolute; left:0; top:0; width:36px; height:36px; border-radius:50%; background:var(--navy); color:var(--bg); font-weight:700; display:flex; align-items:center; justify-content:center; }
        .steps h3 { font-size:17px; margin:4px 0 4px; color:var(--ink); }
        .tip { background:var(--tip); border-radius:10px; padding:10px 14px; font-size:14.5px; margin:10px 0 0; }
        .say { font-weight:600; }
        code { font-family:'IBM Plex Mono', ui-monospace, monospace; font-size:12.5px; overflow-wrap:anywhere; }
        footer { border-top:1px solid var(--line); color:var(--muted); font-size:13px; }
        footer .w { padding:20px; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        footer a { color:var(--muted); text-decoration:none; }
    </style>
    @endverbatim
</head>
<body>
    <header>
        <div class="w{{ \App\Support\Locales::multipleEnabled() ? ' has-locale' : '' }}"><a href="{{ url('/') }}" class="brand">PENTANA</a><x-locale.switcher /></div>
    </header>

    <main class="w">
        <h1>{{ __('Install PENTANA on Android') }}</h1>
        <p class="lede">{{ __('PENTANA is not on Google Play yet, so you install it straight from this website. It takes about two minutes and needs Android 8.0 or newer.') }}</p>

        <div class="get">
            @if ($androidRelease)
                <a class="btn" href="{{ route('android.download') }}" download>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                    {{ __('Download for Android') }}
                </a>
                <p class="meta">{{ __('Version :version · :size MB', ['version' => $androidRelease->version_name, 'size' => $androidRelease->sizeInMegabytes()]) }}</p>
            @else
                <p class="meta">{{ __('The Android app is not available to download yet. Check back soon.') }}</p>
            @endif
        </div>

        <ol class="steps">
            <li>
                <h3>{{ __('Download the app') }}</h3>
                <p>{{ __('On your Android phone, open this page in Chrome or your usual browser and tap Download for Android.') }}</p>
                <p class="tip">{{ __('If your browser warns that the file might be harmful, tap Download anyway. Browsers show this for every app that does not come from Google Play.') }}</p>
            </li>
            <li>
                <h3>{{ __('Open the file') }}</h3>
                <p>{{ __('When the download finishes, tap Open in the browser or in the notification. You can also find the file in the Files app under Downloads.') }}</p>
            </li>
            <li>
                <h3>{{ __('Allow installs from your browser') }}</h3>
                <p>{{ __('The first time, Android says your phone is not allowed to install unknown apps from this source. Tap Settings, turn on Allow from this source, then go back.') }}</p>
                <p class="tip">{{ __('You only need to do this once. It applies to your browser, not to every app.') }}</p>
            </li>
            <li>
                <h3>{{ __('Install') }}</h3>
                <p>{{ __('Tap Install and wait a few seconds.') }}</p>
                <p class="tip">{{ __('If Google Play Protect says the app is from an unknown developer or offers to scan it, tap More details, then Install anyway. Some phones, such as Huawei, Honor and Xiaomi, show their own security check: choose the option to continue installing.') }}</p>
            </li>
            <li>
                <h3>{{ __('Open and sign in') }}</h3>
                <p>{!! __('Tap Open and sign in with your email and password. Have not claimed your account yet? :claim first.', ['claim' => '<a href="'.e(route('claim')).'">'.e(__('Claim your account')).'</a>']) !!}</p>
            </li>
        </ol>

        <h2>{{ __('Updating') }}</h2>
        <p>{{ __('The app tells you when a new version is ready. Tap Update, then open the file and tap Install, just like steps 2 and 4. You stay signed in and nothing is lost.') }}</p>

        <h2>{{ __('If something goes wrong') }}</h2>
        <ul>
            <li><span class="say">{{ __('“App not installed” or “conflicts with an existing package”') }}</span>: {{ __('an older test copy of PENTANA is on your phone. Uninstall it, then install again.') }}</li>
            <li><span class="say">{{ __('Cannot find the downloaded file') }}</span>: {{ __('open the Files app and look in Downloads for a file starting with pentana.') }}</li>
            <li><span class="say">{{ __('The Install button is greyed out') }}</span>: {{ __('close any screen dimming or bubble apps, then try again.') }}</li>
        </ul>

        <h2>{{ __('Stay safe') }}</h2>
        <p>{{ __('Only download PENTANA from this website. Never install a copy someone sends you in a chat or email.') }}</p>
        @if ($androidRelease)
            <p class="meta">{{ __('SHA-256 of the current file:') }} <code>{{ $androidRelease->sha256 }}</code></p>
        @endif

        <p>{{ __('Still stuck? Email us:') }} <a href="mailto:hipentana@pentana.silentmode.net">hipentana@pentana.silentmode.net</a></p>
    </main>

    <footer>
        <div class="w">
            <span>&copy; {{ date('Y') }} PENTANA</span>
            <a href="{{ url('/') }}">pentana.silentmode.net</a>
        </div>
    </footer>
<x-locale.chooser />
</body>
</html>
