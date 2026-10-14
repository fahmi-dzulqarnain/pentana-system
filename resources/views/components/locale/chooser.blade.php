@if (\App\Support\Locales::multipleEnabled() && ! ($localeChosen ?? false))
<div data-locale-chooser class="lc-overlay" role="dialog" aria-modal="true" aria-labelledby="lc-title">
    <div class="lc-card">
        <h2 id="lc-title" class="lc-title">Choose your language · Pilih bahasa anda · Pilih bahasa Anda</h2>
        <div class="lc-options">
            @foreach (\App\Support\Locales::enabled() as $code)
                <form method="POST" action="{{ route('locale.update') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $code }}">
                    <button type="submit" class="lc-option" data-locale-option="{{ $code }}" lang="{{ $code }}">
                        {{ \App\Support\Locales::nativeName($code) }}
                    </button>
                </form>
            @endforeach
        </div>
    </div>
</div>
<script>
(function () {
    // Pre-highlight the browser's language without choosing it.
    var langs = (navigator.languages || [navigator.language || '']).map(function (l) { return l.slice(0, 2).toLowerCase(); });
    var match = langs.find(function (l) { return document.querySelector('[data-locale-option="' + l + '"]'); }) || 'en';
    var btn = document.querySelector('[data-locale-option="' + match + '"]');
    if (btn) { btn.classList.add('is-suggested'); btn.focus(); }
})();
</script>
<style>
.lc-overlay{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(10,9,18,.6);backdrop-filter:blur(4px)}
.lc-card{width:100%;max-width:400px;border-radius:20px;padding:24px;background:#fff;color:#101114;box-shadow:0 16px 40px rgba(0,0,0,.3);font-family:system-ui,sans-serif}
.lc-title{margin:0 0 16px;font-size:16px;font-weight:700;line-height:1.4;text-align:center}
.lc-options{display:flex;flex-direction:column;gap:10px}
.lc-option{width:100%;min-height:52px;border-radius:12px;border:1px solid #DEDEE5;background:#fff;color:inherit;font:600 16px/1 system-ui,sans-serif;cursor:pointer}
.lc-option:hover,.lc-option:focus-visible{border-color:#F7931E;outline:none}
.lc-option.is-suggested{border:2px solid #F7931E;background:#FFF8F0}
@media (prefers-color-scheme:dark){.lc-card{background:#1A1729;color:#F2F1F9}.lc-option{background:#1A1729;border-color:rgba(255,255,255,.22)}.lc-option.is-suggested{background:rgba(247,147,30,.12)}}
</style>
@endif
