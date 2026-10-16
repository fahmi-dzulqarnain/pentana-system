<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <x-locale.sync-script />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PENTANA | {{ __('Welfare & membership') }}</title>
    <meta name="apple-itunes-app" content="app-id=6784914158">
    <meta name="description" content="{{ __('PENTANA is the member companion to your organisation\'s welfare fund. Pay monthly dues, vote on lunch, and register for activities.') }}">

    <link rel="icon" type="image/png" href="{{ asset('images/pentana-mark.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    @verbatim
    <style>
    /* ============================================================
       PENTANA landing page - from the Claude Design handoff.
       Token system (light + auto dark) reproduced from the design's
       DS + landing tokens; brand orange #F7931E + deep indigo #1B1464.
       ============================================================ */
    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { margin: 0; }

    .pl {
        --font-display: 'Plus Jakarta Sans', 'IBM Plex Sans', system-ui, sans-serif;
        --font-ui: 'IBM Plex Sans', system-ui, -apple-system, sans-serif;
        --font-mono: 'IBM Plex Mono', ui-monospace, 'SF Mono', Menlo, monospace;

        --orange-500:#F7931E; --orange-600:#E27D0A; --orange-800:#8C4D08;
        --navy-800:#1B1464; --navy-900:#140E48; --navy-600:#322670; --navy-400:#7269BE;
        --navy-200:#CBC8E8; --navy-100:#E5E3F3; --navy-50:#F2F1F9;
        --ink:#101114; --gray-600:#686B82; --gray-550:#6B6E84; --gray-500:#9497A9;
        --gray-400:#B9BBC7; --gray-300:#DEDEE5; --gray-100:#F4F4F7; --gray-50:#FAFAFB; --white:#fff;
        --green-dark:#026B3F; --green-subtle:rgba(20,158,97,.14);
        --amber-dark:#93370D; --amber-subtle:rgba(220,104,3,.12);

        --text-primary:var(--ink); --text-secondary:var(--gray-600); --text-muted:var(--gray-550);
        --text-brand:var(--orange-800); --text-link:var(--navy-800); --text-link-hover:var(--orange-800);
        --text-on-brand:var(--navy-900);
        --surface-page:var(--white); --surface-subtle:var(--gray-50); --surface-card:var(--white);
        --border-subtle:var(--gray-300); --border-strong:var(--gray-400);
        --shadow-card:0 1px 2px rgba(16,20,40,.04),0 2px 8px rgba(16,20,40,.05);
        --shadow-lg:0 12px 32px rgba(16,20,40,.12);
        --shadow-brand:0 6px 20px rgba(247,147,30,.30);

        --pl-heading:var(--navy-800);
        --pl-nav-bg:rgba(255,255,255,.85);
        --pl-band:linear-gradient(125deg,var(--navy-800),var(--navy-600));
        --pl-footer:var(--navy-900);
        --pl-mark-opacity:.12;

        --dues:#155EEF;  --dues-bg:rgba(21,94,239,.10);  --dues-text:#1849A9;
        --lunch:#E27D0A; --lunch-bg:rgba(247,147,30,.14); --lunch-text:#B86407;
        --activ:#0E8A53; --activ-bg:rgba(20,158,97,.12);  --activ-text:#026B3F;
        --proof:#6B4DE6; --proof-bg:rgba(107,77,230,.12); --proof-text:#4F35BD;

        font-family: var(--font-ui);
        color: var(--text-primary);
        background: var(--surface-page);
        font-size: 16px;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }
    .pl *, .pl *::before, .pl *::after { box-sizing: border-box; }
    .pl h1, .pl h2, .pl h3 { text-wrap: balance; margin: 0; }
    .pl p { text-wrap: pretty; margin: 0; }
    .pl svg { stroke-linecap: round; stroke-linejoin: round; }
    :where(.pl) a { color: inherit; }

    /* Dark theme - applied by JS via data-pl-theme (resolves the OS default on first
       load, then remembers an explicit toggle). Single block, no media query, so a
       manual choice always wins. */
    html[data-pl-theme="dark"] .pl {
        --surface-page:#0D0B16; --surface-subtle:#12101E; --surface-card:#1A1729;
        --ink:#F2F1F9; --text-primary:#F2F1F9; --text-secondary:#B2AFC8; --text-muted:#9693AE;
        --text-link:#C9C4F0; --text-link-hover:#FAB85B; --text-brand:#FAB85B;
        --border-subtle:rgba(255,255,255,.12); --border-strong:rgba(255,255,255,.22);
        --navy-50:rgba(114,105,190,.20);
        --green-dark:#34C77E; --green-subtle:rgba(20,158,97,.20);
        --amber-dark:#FBB04C; --amber-subtle:rgba(251,176,76,.18);
        --shadow-card:0 1px 2px rgba(0,0,0,.45),0 2px 8px rgba(0,0,0,.35);
        --shadow-lg:0 16px 40px rgba(0,0,0,.55);
        --pl-heading:#fff; --pl-nav-bg:rgba(13,11,22,.82);
        --pl-band:linear-gradient(125deg,#1B1464,#322670); --pl-footer:#0A0912; --pl-mark-opacity:.08;
        --dues:#6CA0FF;  --dues-bg:rgba(108,160,255,.16); --dues-text:#8FB5FF;
        --lunch:#FBB04C; --lunch-bg:rgba(247,147,30,.18);  --lunch-text:#FBB04C;
        --activ:#34C77E; --activ-bg:rgba(45,192,126,.16);  --activ-text:#4FD394;
        --proof:#A78BFF; --proof-bg:rgba(167,139,255,.18); --proof-text:#B9A3FF;
    }

    .pl-wrap { max-width: 1160px; margin: 0 auto; }
    .pl-eyebrow { font-size:12.5px; font-weight:700; letter-spacing:.09em; text-transform:uppercase; color:var(--text-brand); }
    .pl-eyebrow.on-dark { color:rgba(255,255,255,.62); }
    .pl-link { color:var(--text-link); text-decoration:none; font-weight:600; transition:color 140ms ease; }
    .pl-link:hover { color:var(--text-link-hover); }
    .pl-mark-bg { position:absolute; pointer-events:none; }

    /* wordmark */
    .pl-brand { display:inline-flex; align-items:center; gap:10px; }
    .pl-brand img { width:30px; height:30px; border-radius:9px; }
    .pl-brand span { font-family:var(--font-display); font-weight:800; font-size:19px; letter-spacing:.14em; color:var(--pl-heading); }
    .pl-brand.on-dark span { color:#fff; }

    /* buttons */
    .pl-btn { display:inline-flex; align-items:center; justify-content:center; gap:9px; height:52px; padding:0 22px;
        border-radius:12px; font-family:var(--font-ui); font-size:15.5px; font-weight:600; text-decoration:none;
        border:1px solid transparent; cursor:pointer; transition:background 140ms ease, border-color 140ms ease; white-space:nowrap; }
    .pl .pl-btn--primary { background:var(--orange-500); color:var(--text-on-brand); box-shadow:var(--shadow-brand); }
    .pl .pl-btn--primary:hover { background:var(--orange-600); }
    .pl-btn--outline { background:transparent; color:var(--text-primary); border-color:var(--border-strong); }
    .pl-btn--outline:hover { background:color-mix(in srgb, var(--text-primary) 6%, transparent); }
    html[data-pl-theme="dark"] .pl-btn--outline { color:#F2F1F9; border-color:rgba(255,255,255,.28); background:rgba(255,255,255,.04); }
    html[data-pl-theme="dark"] .pl-btn--outline:hover { background:rgba(255,255,255,.09); }

    /* top bar */
    .pl-nav { position:sticky; top:0; z-index:20; background:var(--pl-nav-bg); backdrop-filter:blur(12px);
        border-bottom:1px solid var(--border-subtle); }
    .pl-nav .pl-wrap { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:13px 32px; }
    .pl-nav nav { display:flex; align-items:center; gap:26px; }
    .pl-nav nav a { font-size:14.5px; display:inline-flex; align-items:center; min-height:44px; }
    .pl-nav nav.has-locale a { white-space:nowrap; }
    .pl-theme-toggle { display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:10px;
        border:1px solid var(--border-subtle); background:transparent; color:var(--text-secondary); cursor:pointer;
        transition:background 140ms ease, color 140ms ease; }
    .pl-theme-toggle:hover { background:color-mix(in srgb, var(--text-primary) 6%, transparent); color:var(--text-primary); }
    .pl-theme-toggle .sun { display:none; }
    html[data-pl-theme="dark"] .pl-theme-toggle .sun { display:inline-flex; }
    html[data-pl-theme="dark"] .pl-theme-toggle .moon { display:none; }

    /* hero */
    .pl-hero { position:relative; overflow:hidden; }
    .pl-hero .pl-wrap { display:grid; grid-template-columns:1.1fr 0.9fr; gap:56px; align-items:center; padding:76px 32px 88px; }
    .pl-hero h1 { font-family:var(--font-display); font-weight:800; font-size:58px; line-height:1.05; letter-spacing:-1.8px; color:var(--pl-heading); margin-top:16px; }
    .pl-hero .sub { font-size:18px; color:var(--text-secondary); line-height:1.55; margin-top:18px; max-width:480px; }
    .pl-hero .cta { display:flex; gap:12px; margin-top:28px; flex-wrap:wrap; }
    .pl-hero .hero-signin { margin-top:12px; font-size:13.5px; color:var(--text-muted); }
    .pl-hero .hero-signin a { display:inline-flex; align-items:center; min-height:44px; }
    .pl-hero .snap-col { display:flex; justify-content:flex-end; padding-right:24px; }

    /* hero snapshot card */
    .pl-snap { position:relative; width:360px; max-width:380px; }
    .pl-snap-card { position:relative; z-index:2; background:var(--surface-card); border:1px solid var(--border-subtle);
        border-radius:20px; padding:22px; box-shadow:var(--shadow-lg); }
    .pl-snap-top { display:flex; justify-content:space-between; align-items:center; }
    .pl-snap-top .lbl { font-size:12.5px; font-weight:600; color:var(--text-muted); }
    .pl-pill-ok { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:99px; background:var(--green-subtle); color:var(--green-dark); font-size:12px; font-weight:700; }
    .pl-snap-money { font-family:var(--font-mono); font-size:32px; font-weight:700; letter-spacing:-.5px; margin-top:6px; color:var(--text-primary); }
    .pl-snap-recpt { font-size:12.5px; color:var(--text-muted); margin-top:2px; }
    .pl-snap-div { height:1px; background:var(--border-subtle); margin:16px 0 12px; }
    .pl-snap-rows { display:flex; flex-direction:column; gap:10px; }
    .pl-snap-row { display:flex; align-items:center; gap:10px; }
    .pl-snap-row .ic { width:30px; height:30px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; flex:none; }
    .pl-snap-row .txt { font-size:13.5px; font-weight:500; flex:1; }
    .pl-snap-row .act { font-size:12px; font-weight:700; }

    /* features */
    .pl-features { background:var(--surface-subtle); border-top:1px solid var(--border-subtle); border-bottom:1px solid var(--border-subtle); }
    .pl-features .pl-wrap { padding:72px 32px 80px; }
    .pl-features h2 { font-family:var(--font-display); font-size:36px; font-weight:700; letter-spacing:-.9px; color:var(--pl-heading); margin-top:12px; }
    .pl-features .head { max-width:560px; margin-bottom:40px; }
    .pl-grid4 { display:grid; grid-template-columns:repeat(4,1fr); gap:18px; }
    .pl-card { border-radius:16px; padding:22px 22px 24px; box-shadow:var(--shadow-card); display:flex; flex-direction:column; gap:14px; }
    .pl-card .chip { width:46px; height:46px; border-radius:13px; flex:none; display:inline-flex; align-items:center; justify-content:center; }
    .pl-card .t { font-family:var(--font-display); font-size:17.5px; font-weight:700; color:var(--text-primary); }
    .pl-card p { font-size:14px; color:var(--text-secondary); line-height:1.5; margin-top:6px; }
    .pl-card.dues  { background:linear-gradient(0deg,var(--dues-bg),var(--dues-bg)),var(--surface-card);   border:1px solid color-mix(in srgb,var(--dues) 26%,transparent); }
    .pl-card.lunch { background:linear-gradient(0deg,var(--lunch-bg),var(--lunch-bg)),var(--surface-card); border:1px solid color-mix(in srgb,var(--lunch) 26%,transparent); }
    .pl-card.activ { background:linear-gradient(0deg,var(--activ-bg),var(--activ-bg)),var(--surface-card); border:1px solid color-mix(in srgb,var(--activ) 26%,transparent); }
    .pl-card.proof { background:linear-gradient(0deg,var(--proof-bg),var(--proof-bg)),var(--surface-card); border:1px solid color-mix(in srgb,var(--proof) 26%,transparent); }
    .pl-card.dues .chip  { background:var(--dues-bg); color:var(--dues); }
    .pl-card.lunch .chip { background:var(--lunch-bg); color:var(--lunch); }
    .pl-card.activ .chip { background:var(--activ-bg); color:var(--activ); }
    .pl-card.proof .chip { background:var(--proof-bg); color:var(--proof); }

    /* get the app band */
    .pl-getapp { padding:80px 32px; }
    .pl-band { background:var(--pl-band); border-radius:28px; position:relative; overflow:hidden;
        display:grid; grid-template-columns:1.1fr 0.9fr; gap:24px; }
    .pl-band .copy { position:relative; z-index:2; padding:60px 0 60px 56px; }
    .pl-band h2 { font-family:var(--font-display); font-size:36px; font-weight:700; letter-spacing:-.9px; color:#fff; margin-top:12px; }
    .pl-band .copy p.sub { font-size:16.5px; color:rgba(255,255,255,.78); line-height:1.55; margin-top:12px; max-width:440px; }
    .pl-band .badges { display:flex; gap:12px; margin-top:26px; flex-wrap:wrap; align-items:center; }
    .pl-band .note { font-size:13px; color:rgba(255,255,255,.6); margin-top:16px; max-width:420px; }
    .pl-storebadge { display:inline-flex; align-items:center; gap:10px; height:52px; padding:0 18px 0 14px; border-radius:12px;
        background:#101114; color:#fff; border:1px solid rgba(255,255,255,.22); white-space:nowrap; text-decoration:none;
        transition:border-color 140ms ease, background 140ms ease; }
    a.pl-storebadge:hover { border-color:rgba(255,255,255,.5); background:#000; }
    a.pl-storebadge:focus-visible { outline:2px solid #fff; outline-offset:3px; }
    .pl-storebadge.is-soon { opacity:.5; cursor:default; user-select:none; }
    .pl-storebadge .two { display:flex; flex-direction:column; line-height:1.15; text-align:left; }
    .pl-storebadge .two .s { font-size:10.5px; opacity:.75; letter-spacing:.02em; }
    .pl-storebadge .two .b { font-family:var(--font-display); font-size:16.5px; font-weight:700; letter-spacing:-.01em; }
    .pl-soon { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:99px; background:var(--amber-subtle); color:var(--amber-dark); font-size:12px; font-weight:700; }
    .pl-soon .dot { width:6px; height:6px; border-radius:3px; background:currentColor; }
    .pl-phonecol { position:relative; z-index:1; min-height:460px; overflow:hidden; display:flex; justify-content:center; }
    .pl-phone { position:absolute; top:56px; width:236px; height:488px; background:#0b0b0f; border-radius:40px; padding:9px;
        box-shadow:0 24px 56px rgba(0,0,0,.4); }
    .pl-phone-screen { width:100%; height:100%; border-radius:32px; overflow:hidden; background:#FFF8F4; padding:16px 14px; }
    .pl-mini-hero { background:#FFDCC2; color:#3A1A00; border-radius:16px 16px 16px 6px; padding:12px 14px; }
    .pl-mini-hero .d { font-size:10.5px; opacity:.8; }
    .pl-mini-hero .h { font-size:18px; font-weight:700; margin-top:1px; }
    .pl-mini-card { background:#fff; border-radius:14px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:12px; margin-top:10px; }
    .pl-mini-row { display:flex; justify-content:space-between; align-items:center; }
    .pl-mini-row .l { font-size:10.5px; color:#8a7f74; font-weight:600; }
    .pl-mini-row .p { font-size:10px; font-weight:700; color:#026B3F; background:rgba(20,158,97,.14); border-radius:99px; padding:2px 7px; }
    .pl-mini-money { font-family:var(--font-mono); font-size:22px; font-weight:700; color:#211a14; margin-top:4px; }
    .pl-mini-item { display:flex; align-items:center; gap:8px; font-size:12px; font-weight:500; color:#211a14; }
    .pl-mini-item + .pl-mini-item { margin-top:10px; }
    .pl-mini-item .mc { width:26px; height:26px; border-radius:8px; flex:none; display:inline-flex; align-items:center; justify-content:center; }
    .pl-mini-item .end { margin-left:auto; font-size:11px; font-weight:700; }

    /* how it works */
    .pl-how { padding:8px 32px 88px; }
    .pl-how .head { max-width:560px; margin-bottom:36px; }
    .pl-how h2 { font-family:var(--font-display); font-size:36px; font-weight:700; letter-spacing:-.9px; color:var(--pl-heading); margin-top:12px; }
    .pl-grid3 { display:grid; grid-template-columns:repeat(3,1fr); gap:28px; }
    .pl-step { display:flex; gap:16px; align-items:flex-start; }
    .pl-step .num { width:44px; height:44px; border-radius:12px; flex:none; background:var(--navy-50); color:var(--text-link);
        display:inline-flex; align-items:center; justify-content:center; font-family:var(--font-mono); font-size:15px; font-weight:700; }
    .pl-step .t { font-family:var(--font-display); font-size:17.5px; font-weight:700; color:var(--text-primary); }
    .pl-step p { font-size:14px; color:var(--text-secondary); line-height:1.5; margin-top:6px; max-width:280px; }

    /* footer */
    .pl-footer { background:var(--pl-footer); color:#fff; }
    .pl-footer .top { display:flex; justify-content:space-between; gap:48px; flex-wrap:wrap; padding:48px 32px 28px; max-width:1160px; margin:0 auto; }
    .pl-footer .about { max-width:300px; }
    .pl-footer .about p { font-size:13.5px; color:#9497A9; line-height:1.55; margin-top:14px; }
    .pl-footer .about a.mail { font-size:13.5px; color:#CBC8E8; text-decoration:none; font-family:var(--font-mono); display:inline-block; margin-top:4px; padding:6px 0; max-width:100%; overflow-wrap:anywhere; }
    .pl-footer .cols { display:flex; gap:56px; flex-wrap:wrap; }
    .pl-footer .col h4 { font-size:12px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#7269BE; margin:0 0 6px; }
    .pl-footer .col a { display:block; font-size:13.5px; color:#B9BBC7; text-decoration:none; padding:5px 0; }
    .pl-footer .col a:hover { color:#fff; }
    .pl-footer .bar { border-top:1px solid rgba(255,255,255,.1); display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;
        font-size:12.5px; color:#686B82; padding:20px 32px 26px; max-width:1160px; margin:0 auto; }

    /* ---------- mobile ---------- */
    @media (max-width: 880px) {
      .pl-nav .pl-wrap { padding:14px 20px; }
      .pl-nav nav { gap:18px; } .pl-nav nav a { font-size:13.5px; }
      .pl-hero .pl-wrap { display:flex; flex-direction:column; gap:44px; padding:48px 20px 56px; }
      .pl-hero h1 { font-size:38px; letter-spacing:-1.2px; }
      .pl-hero .sub { font-size:16.5px; }
      .pl-hero .snap-col { justify-content:center; padding-right:0; }
      .pl-snap { width:100%; }
      .pl-features .pl-wrap { padding:48px 20px 52px; }
      .pl-features h2, .pl-band h2, .pl-how h2 { font-size:27px; }
      .pl-features .head { margin-bottom:28px; }
      .pl-grid4 { grid-template-columns:1fr; gap:12px; }
      .pl-getapp { padding:48px 14px; }
      .pl-band { display:flex; flex-direction:column; border-radius:22px; }
      .pl-band .copy { padding:36px 26px 8px; }
      .pl-phonecol { min-height:0; height:330px; margin-top:12px; }
      .pl-phone { top:0; }
      .pl-how { padding:10px 20px 56px; }
      .pl-grid3 { grid-template-columns:1fr; gap:18px; }
      .pl-footer .top { flex-direction:column; gap:28px; padding:36px 20px 26px; }
      .pl-footer .col a { padding:12px 0; }
    }
    @media (max-width: 480px) {
      .pl-nav-claim { display:none !important; }
      .pl-nav nav.has-locale { gap:12px; }
    }
    </style>
    @endverbatim
    {{-- Resolve theme before paint: explicit choice from localStorage, else the OS default. No flash. --}}
    <script>
    (function(){try{var s=localStorage.getItem('pl-theme');var d=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches;document.documentElement.setAttribute('data-pl-theme',(s==='light'||s==='dark')?s:(d?'dark':'light'));}catch(e){document.documentElement.setAttribute('data-pl-theme','light');}})();
    </script>
</head>
<body>
<div class="pl">

    {{-- 1 · Top bar --}}
    <header class="pl-nav">
        <div class="pl-wrap">
            <a href="/" class="pl-brand" aria-label="{{ __('PENTANA home') }}">
                <img src="{{ asset('images/pentana-mark.png') }}" alt="">
                <span>PENTANA</span>
            </a>
            <nav{!! \App\Support\Locales::multipleEnabled() ? ' class="has-locale"' : '' !!}>
                <a href="{{ route('claim') }}" class="pl-link pl-nav-claim">{{ __('Claim account') }}</a>
                <a href="{{ url('/account') }}" class="pl-link">{{ __('Sign in') }}</a>
                <x-locale.switcher />
                <button type="button" class="pl-theme-toggle" id="pl-theme-toggle" aria-label="{{ __('Toggle dark mode') }}" title="{{ __('Toggle dark mode') }}">
                    <svg class="moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                    <svg class="sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                </button>
            </nav>
        </div>
    </header>

    {{-- 2 · Hero --}}
    <section class="pl-hero">
        <div class="pl-wrap">
            <div>
                <div class="pl-eyebrow">PENTANA · {{ __('member companion') }}</div>
                <h1>{{ __('Your dues, lunch and activities, all in one place.') }}</h1>
                <p class="sub">{{ __('PENTANA is the member companion to your organisation\'s welfare fund. Pay monthly dues, vote on lunch, and register for activities.') }}</p>
                <div class="cta">
                    <a href="{{ route('claim') }}" class="pl-btn pl-btn--primary">
                        {{ __('Claim your account') }}
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                    <a href="#get-app" class="pl-btn pl-btn--outline">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/></svg>
                        {{ __('Get the app') }}
                    </a>
                </div>
                <div class="hero-signin">{{ __('Already claimed your account?') }} <a href="{{ url('/account') }}" class="pl-link" style="font-size:13.5px">{{ __('Sign in') }} →</a></div>
            </div>
            <div class="snap-col">
                <div class="pl-snap">
                    <div class="pl-snap-card">
                        <div class="pl-snap-top">
                            <span class="lbl">{{ __('This month\'s dues') }}</span>
                            <span class="pl-pill-ok"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg> {{ __('Paid') }}</span>
                        </div>
                        <div class="pl-snap-money">MYR 70.00</div>
                        <div class="pl-snap-recpt">{{ __('June 2026') }} · {{ __('receipt :number', ['number' => '#PT-48213']) }}</div>
                        <div class="pl-snap-div"></div>
                        <div class="pl-snap-rows">
                            <div class="pl-snap-row">
                                <span class="ic" style="background:var(--lunch-bg);color:var(--lunch)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg></span>
                                <span class="txt">{{ __('Lunch vote closes Friday') }}</span>
                                <span class="act" style="color:var(--lunch-text)">{{ __('Vote') }}</span>
                            </div>
                            <div class="pl-snap-row">
                                <span class="ic" style="background:var(--activ-bg);color:var(--activ)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg></span>
                                <span class="txt">{{ __('Hiking at :place', ['place' => 'Bukit Tabur']) }} · 3 Jul</span>
                                <span class="act" style="color:var(--activ-text)">{{ __('Registered') }}</span>
                            </div>
                        </div>
                    </div>
                    <img src="{{ asset('images/pentana-mark.png') }}" alt="" class="pl-mark-bg" style="width:300px;height:300px;right:-110px;top:-80px;opacity:var(--pl-mark-opacity);transform:rotate(12deg);z-index:1">
                </div>
            </div>
        </div>
    </section>

    {{-- 3 · Features --}}
    <section class="pl-features">
        <div class="pl-wrap">
            <div class="head">
                <div class="pl-eyebrow">{{ __('What members do here') }}</div>
                <h2>{{ __('Everything your membership covers') }}</h2>
            </div>
            <div class="pl-grid4">
                <div class="pl-card dues">
                    <span class="chip"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg></span>
                    <div><div class="t">{{ __('Dues & payments') }}</div><p>{{ __('See what you owe each month and submit payment proof in seconds.') }}</p></div>
                </div>
                <div class="pl-card lunch">
                    <span class="chip"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg></span>
                    <div><div class="t">{{ __('Lunch') }}</div><p>{{ __('Vote on the catered menu before each lunch is locked in.') }}</p></div>
                </div>
                <div class="pl-card activ">
                    <span class="chip"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/></svg></span>
                    <div><div class="t">{{ __('Activities') }}</div><p>{{ __('Register for events and outings, and see who else is going.') }}</p></div>
                </div>
                <div class="pl-card proof">
                    <span class="chip"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></span>
                    <div><div class="t">{{ __('Stay in the loop') }}</div><p>{{ __('One dashboard plus notifications for anything that needs you.') }}</p></div>
                </div>
            </div>
        </div>
    </section>

    {{-- 4 · Get the app --}}
    <section class="pl-getapp" id="get-app">
        <div class="pl-band">
            <img src="{{ asset('images/pentana-mark.png') }}" alt="" class="pl-mark-bg" style="width:340px;height:340px;left:-110px;bottom:-130px;opacity:.1;transform:rotate(-14deg)">
            <div class="copy">
                <div class="pl-eyebrow on-dark">{{ __('Get the app') }}</div>
                <h2>{{ __('Take PENTANA with you') }}</h2>
                <p class="sub">{{ __('Dues, lunch votes and activity sign-ups, all in a native app for iOS and Android.') }}</p>
                <div class="badges">
                    <a class="pl-storebadge" href="https://apps.apple.com/my/app/pentana/id6784914158" target="_blank" rel="noopener" aria-label="{{ __('Download PENTANA on the App Store') }}">
                        <svg width="22" height="22" viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.7-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>
                        <span class="two"><span class="s">{{ __('Download on the') }}</span><span class="b">App Store</span></span>
                    </a>
                    <span class="pl-storebadge is-soon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="#00D7FE" d="M3.9 1.6c-.55.3-.9.88-.9 1.55v17.7c0 .67.35 1.25.9 1.55L14.6 12 3.9 1.6z"/><path fill="#00F076" d="M5.3 1.06 17.5 8.1l-2.9 2.83L5.3 1.06z"/><path fill="#FF3A44" d="M5.3 22.94 14.6 13.07l2.9 2.83L5.3 22.94z"/><path fill="#FFC900" d="M17.5 8.1 14.6 11v2.07l2.9 2.83 3.3-1.9c1.2-.7 1.2-2.3 0-3L17.5 8.1z"/></svg>
                        <span class="two"><span class="s">{{ __('GET IT ON') }}</span><span class="b">Google Play</span></span>
                    </span>
                    <span class="pl-soon"><span class="dot"></span> {{ __('Coming soon to Android') }}</span>
                </div>
                <p class="note">{!! __('Android is on the way. :claim now and we\'ll let you know when it lands.', ['claim' => '<a href="'.e(route('claim')).'" style="color:#fff;font-weight:600;text-decoration:underline">'.e(__('Claim your account')).'</a>']) !!}</p>
            </div>
            <div class="pl-phonecol">
                <div class="pl-phone">
                    <div class="pl-phone-screen">
                        <div class="pl-mini-hero"><div class="d">{{ __('Friday, 27 June') }}</div><div class="h">{{ __('Hi, :name', ['name' => 'Aisyah']) }}</div></div>
                        <div class="pl-mini-card">
                            <div class="pl-mini-row"><span class="l">{{ __('This month\'s dues') }}</span><span class="p">{{ __('Paid') }}</span></div>
                            <div class="pl-mini-money">MYR 70.00</div>
                        </div>
                        <div class="pl-mini-card">
                            <div class="pl-mini-item">
                                <span class="mc" style="background:var(--lunch-bg);color:var(--lunch)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg></span>
                                {{ __('Lunch vote') }} <span class="end" style="color:var(--lunch-text)">{{ __('Vote') }}</span>
                            </div>
                            <div class="pl-mini-item">
                                <span class="mc" style="background:var(--activ-bg);color:var(--activ)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg></span>
                                Bukit Tabur <span class="end" style="color:var(--activ-text)">3 Jul</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 5 · How it works --}}
    <section class="pl-how" id="how">
        <div class="head">
            <div class="pl-eyebrow">{{ __('How it works') }}</div>
            <h2>{{ __('Up and running in three steps') }}</h2>
        </div>
        <div class="pl-grid3">
            <div class="pl-step">
                <span class="num">01</span>
                <div><div class="t">{{ __('Claim your account') }}</div><p>{{ __('Verify your details on the web and set a password or passkey.') }}</p></div>
            </div>
            <div class="pl-step">
                <span class="num">02</span>
                <div><div class="t">{{ __('Download the app') }}</div><p>{{ __('Get PENTANA on the App Store. Android is coming soon.') }}</p></div>
            </div>
            <div class="pl-step">
                <span class="num">03</span>
                <div><div class="t">{{ __('Sign in') }}</div><p>{{ __('Sign in with a password or passkey. Your dues, lunch and activities are ready.') }}</p></div>
            </div>
        </div>
    </section>

    {{-- 6 · Footer --}}
    <footer class="pl-footer">
        <div class="top">
            <div class="about">
                <span class="pl-brand on-dark"><img src="{{ asset('images/pentana-mark.png') }}" alt=""><span>PENTANA</span></span>
                <p>{{ __('The member companion to your organisation\'s welfare fund.') }}</p>
                <a class="mail" href="mailto:hipentana@pentana.silentmode.net">hipentana@pentana.silentmode.net</a>
            </div>
            <div class="cols">
                <div class="col">
                    <h4>{{ __('Members') }}</h4>
                    <a href="{{ route('claim') }}">{{ __('Claim account') }}</a>
                    <a href="{{ url('/account') }}">{{ __('Sign in') }}</a>
                </div>
                <div class="col">
                    <h4>PENTANA</h4>
                    <a href="#get-app">{{ __('Get the app') }}</a>
                    <a href="{{ route('privacy') }}">{{ __('Privacy') }}</a>
                </div>
            </div>
        </div>
        <div class="bar">
            <span>&copy; {{ date('Y') }} PENTANA. {{ __('All rights reserved.') }}</span>
            <span>pentana.silentmode.net</span>
        </div>
    </footer>

</div>
<script>
(function(){
    var btn = document.getElementById('pl-theme-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var cur = document.documentElement.getAttribute('data-pl-theme') === 'dark' ? 'dark' : 'light';
        var next = cur === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-pl-theme', next);
        try { localStorage.setItem('pl-theme', next); } catch (e) {}
    });
    if (window.matchMedia) {
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
            try { if (localStorage.getItem('pl-theme')) return; } catch (err) {}
            document.documentElement.setAttribute('data-pl-theme', e.matches ? 'dark' : 'light');
        });
    }
})();
</script>
<x-locale.chooser />
</body>
</html>
