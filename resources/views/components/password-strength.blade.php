{{-- Live password meter under a password field (claim wizard, profile). Guidance only: the
     server rule (Password::defaults()) is what actually refuses a password. Scores come from
     resources/js/password-strength.js; until 12 characters the meter tops out at "Fair" so it
     never calls a password the server will refuse "Good". --}}
@props(['userInputs' => [], 'minLength' => \App\Providers\AppServiceProvider::PASSWORD_MIN_LENGTH])

<div
    wire:ignore
    class="pw-meter"
    x-data="{
        level: -1,
        long: false,
        userInputs: @js(array_values(array_filter($userInputs))),
        minLength: @js($minLength),
        labels: @js([__('Weak'), __('Fair'), __('Good'), __('Strong')]),
        hints: @js([__('Too easy to guess'), __('Add another word'), __('Longer is even better'), __('Great password')]),
        async rate(password) {
            this.long = password.length >= this.minLength;
            if (! password) { this.level = -1; return; }
            const score = await window.pentanaPasswordStrength.score(password, this.userInputs);
            const level = Math.max(0, score - 1);
            this.level = this.long ? level : Math.min(level, 1);
        },
        init() {
            const field = $el.closest('.fi-fo-field') ?? $el.parentElement;
            field.addEventListener('focusin', () => window.pentanaPasswordStrength?.preload());
            field.addEventListener('input', (e) => e.target.matches('input') && this.rate(e.target.value));
        },
    }"
>
    <style>
        .pw-meter { margin-top: 0.5rem; font-size: 0.875rem; line-height: 1.4; }
        .pw-meter-bar { display: flex; gap: 4px; }
        .pw-meter-seg { flex: 1; height: 6px; border-radius: 3px; background: var(--gray-200); transition: background-color .15s; }
        .dark .pw-meter-seg { background: rgb(255 255 255 / 0.12); }
        .pw-meter-status { display: flex; justify-content: space-between; gap: 0.75rem; margin: 0.375rem 0 0.5rem; }
        .pw-meter-label { font-weight: 600; }
        .pw-meter-muted { color: var(--gray-500); }
        .pw-meter-rule { display: flex; gap: 0.375rem; align-items: flex-start; color: var(--gray-500); }
        .pw-meter-rule svg { width: 1rem; height: 1rem; flex: none; margin-top: 0.125rem; }
        .pw-meter-ok { color: var(--success-600); }
        .pw-meter-credit { margin-top: 0.375rem; font-size: 0.75rem; color: var(--gray-400); }
        .pw-meter-credit a { color: inherit; text-decoration: underline; }
        /* Two classes deep, so a filled segment beats the .dark unfilled colour above. */
        .pw-meter-l0 { color: var(--danger-600); } .pw-meter .pw-meter-seg.pw-meter-bg0 { background: var(--danger-500); }
        .pw-meter-l1 { color: var(--warning-600); } .pw-meter .pw-meter-seg.pw-meter-bg1 { background: var(--warning-500); }
        .pw-meter-l2 { color: var(--success-500); } .pw-meter .pw-meter-seg.pw-meter-bg2 { background: var(--success-400); }
        .pw-meter-l3 { color: var(--success-700); } .pw-meter .pw-meter-seg.pw-meter-bg3 { background: var(--success-600); }
        /* Phones: the hint goes under the label instead of squeezing beside it. */
        @media (max-width: 480px) { .pw-meter-status { flex-direction: column; gap: 0; } }
    </style>

    <div class="pw-meter-bar" aria-hidden="true">
        <template x-for="i in 4" :key="i">
            <div class="pw-meter-seg" :class="level >= i - 1 ? 'pw-meter-bg' + level : ''"></div>
        </template>
    </div>

    <div class="pw-meter-status" aria-live="polite">
        <span class="pw-meter-label" :class="level < 0 ? 'pw-meter-muted' : 'pw-meter-l' + level"
            x-text="level < 0 ? @js(__('Start typing')) : labels[level]">{{ __('Start typing') }}</span>
        <span class="pw-meter-muted" x-text="level < 0 ? @js(__('Try three or four unrelated words')) : hints[level]">{{ __('Try three or four unrelated words') }}</span>
    </div>

    <div class="pw-meter-rule" :class="long && 'pw-meter-ok'">
        <svg x-show="! long" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/></svg>
        <svg x-show="long" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
        <span>{{ __('At least :count characters', ['count' => $minLength]) }}</span>
    </div>
    <div class="pw-meter-rule" style="margin-top:0.25rem">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>
        <span>{{ __('Checked against known breaches when you continue') }}</span>
    </div>
    <p class="pw-meter-credit">
        {!! __('Rules follow :guidance guidance.', ['guidance' => '<a href="https://pages.nist.gov/800-63-4/sp800-63b.html" target="_blank" rel="noopener noreferrer">NIST SP 800-63B</a>']) !!}
    </p>
</div>
