@if (\App\Support\Locales::multipleEnabled())
<div data-locale-switcher class="ls-root">
    {{-- Wide screens: the compact EN·BM·ID control. --}}
    <div class="ls ls-inline" role="group" aria-label="{{ __('Language') }}">
        @foreach (\App\Support\Locales::enabled() as $code)
            <form method="POST" action="{{ route('locale.update') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $code }}">
                <button type="submit" class="ls-btn {{ app()->getLocale() === $code ? 'is-current' : '' }}"
                        aria-pressed="{{ app()->getLocale() === $code ? 'true' : 'false' }}"
                        aria-label="{{ \App\Support\Locales::nativeName($code) }}" lang="{{ $code }}">
                    {{ \App\Support\Locales::shortLabel($code) }}
                </button>
            </form>
        @endforeach
    </div>
    {{-- Narrow screens: a globe that opens the same choices. --}}
    <details class="ls-menu">
        <summary class="ls-globe" aria-label="{{ __('Language') }}">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20Z"/></svg>
        </summary>
        <div class="ls-pop">
            @foreach (\App\Support\Locales::enabled() as $code)
                <form method="POST" action="{{ route('locale.update') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $code }}">
                    <button type="submit" class="ls-item {{ app()->getLocale() === $code ? 'is-current' : '' }}"
                            aria-pressed="{{ app()->getLocale() === $code ? 'true' : 'false' }}" lang="{{ $code }}">
                        {{ \App\Support\Locales::nativeName($code) }}
                    </button>
                </form>
            @endforeach
        </div>
    </details>
</div>
<style>
.ls-root{position:relative;display:inline-flex}
.ls{display:inline-flex;border:1px solid var(--border-subtle,#DEDEE5);border-radius:10px;overflow:hidden}
.ls form,.ls-pop form{margin:0}
.ls-btn{min-width:40px;min-height:44px;padding:0 10px;border:0;background:transparent;color:var(--text-secondary,#686B82);font:700 12.5px/1 var(--font-ui,system-ui);letter-spacing:.04em;cursor:pointer}
.ls-btn.is-current{background:var(--orange-500,#F7931E);color:var(--navy-900,#140E48)}
.ls-menu{display:none;position:relative}
.ls-globe{list-style:none;display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;border:1px solid var(--border-subtle,#DEDEE5);color:var(--text-secondary,#686B82);cursor:pointer}
.ls-globe::-webkit-details-marker{display:none}
.ls-pop{position:absolute;right:0;top:calc(100% + 6px);z-index:60;min-width:170px;padding:6px;border-radius:12px;border:1px solid var(--border-subtle,#DEDEE5);background:var(--surface-card,#fff);box-shadow:0 8px 24px rgba(0,0,0,.2)}
.ls-item{display:block;width:100%;min-height:44px;padding:0 12px;border:0;border-radius:8px;background:transparent;color:var(--text-primary,#101114);font:600 14px/1 var(--font-ui,system-ui);text-align:left;cursor:pointer}
.ls-item.is-current{background:var(--orange-500,#F7931E);color:var(--navy-900,#140E48)}
@media (max-width:480px){.ls-inline{display:none}.ls-menu{display:block}}
</style>
@endif
