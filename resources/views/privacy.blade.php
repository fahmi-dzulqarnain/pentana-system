<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <x-locale.sync-script />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy | PENTANA</title>
    <meta name="description" content="How PENTANA handles your personal information.">
    <link rel="icon" type="image/png" href="{{ asset('images/pentana-mark.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @verbatim
    <style>
        :root { --ink:#211a14; --muted:#50453b; --bg:#fff8f4; --line:#e5ddd4; --orange:#9a4a00; --navy:#1b1464; }
        @media (prefers-color-scheme: dark) {
            :root { --ink:#efe0d5; --muted:#b8ab9e; --bg:#141019; --line:#2c2740; --orange:#ffb784; --navy:#c8bfff; }
        }
        * { box-sizing: border-box; }
        body { margin:0; font-family:'IBM Plex Sans', system-ui, -apple-system, sans-serif; color:var(--ink); background:var(--bg); line-height:1.6; }
        .w { max-width:740px; margin:0 auto; padding:0 20px; }
        header { border-bottom:1px solid var(--line); }
        header .w { padding:16px 20px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
        header .ls { --border-subtle:var(--line); }
        .brand { font-weight:800; letter-spacing:.12em; color:var(--navy); text-decoration:none; font-size:18px; }
        main { padding:40px 20px 64px; }
        main.w { max-width:740px; }
        h1 { font-size:32px; color:var(--navy); margin:0 0 4px; }
        .updated { color:var(--muted); font-size:14px; margin:0 0 28px; }
        h2 { font-size:20px; color:var(--navy); margin:32px 0 8px; }
        p, li { margin:8px 0; }
        a { color:var(--orange); }
        ul { padding-left:22px; }
        strong { color:var(--ink); }
        footer { border-top:1px solid var(--line); color:var(--muted); font-size:13px; }
        footer .w { padding:20px; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        footer a { color:var(--muted); text-decoration:none; }
    </style>
    @endverbatim
</head>
<body>
    <header>
        <div class="w"><a href="{{ url('/') }}" class="brand">PENTANA</a><x-locale.switcher /></div>
    </header>

    <main class="w">
        <h1>Privacy Policy</h1>
        <p class="updated">Last updated: 7 July 2026</p>

        <p>PENTANA Mobile (“the app”) is the member companion to a welfare &amp; membership fund operated by
        <strong>Pentana Org</strong> (“we”, “us”, “our”). This policy explains what personal information
        we handle when you use PENTANA and the PENTANA web portal, and your rights over it. We handle personal
        data in line with Malaysia’s Personal Data Protection Act 2010 (PDPA).</p>

        <h2>Information we collect</h2>
        <p>We only collect what’s needed to run your membership:</p>
        <ul>
            <li><strong>Account details</strong>: your name, email address, membership category, date of birth,
            and welfare-credit balance. These are provided by your organisation and/or by you when you claim your account.</li>
            <li><strong>Dues &amp; payments</strong>: your monthly dues, balances and payment history, and any
            <strong>payment-proof images</strong>, amounts and notes you submit.</li>
            <li><strong>Lunch responses</strong>: the meal option you choose, or that you are not attending.</li>
            <li><strong>Activity registrations</strong>: your sign-ups and the answers you give to event questions.</li>
            <li><strong>Notifications</strong>: an anonymous device token so we can send push notifications about
            lunches, activities and updates. You can turn notifications off in your device settings at any time.</li>
            <li><strong>Sign-in credentials</strong>: a securely hashed password and/or a passkey (a public key
            registered on your device; we never receive your biometric data).</li>
            <li><strong>Technical data</strong>: basic server logs (such as IP address and request time) kept for
            security and troubleshooting.</li>
        </ul>
        <p>We do <strong>not</strong> use third-party advertising or analytics/tracking SDKs, and we do not track
        you across other apps or websites.</p>

        <h2>How we use your information</h2>
        <ul>
            <li>To show your dues, accept payment proof, and record your lunch and activity responses.</li>
            <li>To send the notifications you’ve allowed.</li>
            <li>To authenticate you and keep your account secure.</li>
            <li>To operate, maintain and improve the service, and to meet welfare-administration and legal obligations.</li>
        </ul>

        <h2>Legal basis</h2>
        <p>We process your data to administer your membership of the welfare fund, and (for push notifications)
        on the basis of your consent.</p>

        <h2>How your information is shared</h2>
        <p>We do <strong>not</strong> sell your personal data. We share it only:</p>
        <ul>
            <li>with <strong>Apple Push Notification service</strong> (and Google, on Android) solely to deliver push notifications;</li>
            <li>with service providers that host our servers, under confidentiality obligations; and</li>
            <li>where required by law.</li>
        </ul>

        <h2>Data retention</h2>
        <p>We keep your information for as long as you are a member and as needed for welfare-fund records and legal
        obligations, after which we delete or anonymise it.</p>

        <h2>Security</h2>
        <p>Data is encrypted in transit (HTTPS). Passwords are stored only as salted hashes; passkeys use public-key
        cryptography and never expose a secret. Payment-proof images are held on access-controlled storage.</p>

        <h2>Your rights</h2>
        <p>You may request access to, correction of, or deletion of your personal data, and you may withdraw consent
        for notifications at any time. Contact us using the details below.</p>

        <h2>Children</h2>
        <p>PENTANA is intended for members of the organisation and is not directed at children under 13.</p>

        <h2>Changes to this policy</h2>
        <p>We may update this policy from time to time. Material changes will be posted here with a new
        “last updated” date.</p>

        <h2>Contact</h2>
        <p>Questions or requests about your data: <a href="mailto:privacy@silentmode.my">privacy@silentmode.my</a>.</p>
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
