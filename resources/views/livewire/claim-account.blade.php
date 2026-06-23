<div wire:key="claim-card">
    <style>
        /* Keep the multi-step Wizard, but render its step header flat: drop the card
           box (transparent background, no ring/shadow/border) and the horizontal
           scrollbar. The header is a column grid with auto-width columns + overflow-x:
           auto, so two steps with long descriptions overflow and scroll. Equal,
           shrinkable columns (minmax(0, 1fr)) make both steps always fit the card. */
        .fi-sc-wizard-header {
            grid-auto-columns: minmax(0, 1fr);
            overflow: visible;
            background-color: transparent;
            box-shadow: none;
            border: 0;
            border-radius: 0;
        }
    </style>

    <h1 class="claim-title" style="font-size:1.5rem;font-weight:700;margin:0 0 0.25rem;color:#111827;">
        Activate your account
    </h1>

    @if ($stage === 'lookup')
        <p class="claim-subtle" style="margin:0 0 1.5rem;color:#6b7280;">
            Enter the email your admin registered for you.
        </p>

        <form wire:submit="lookup">
            <label for="claim-email" class="claim-subtle" style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:0.375rem;color:#374151;">
                Email address
            </label>
            <input
                id="claim-email"
                type="email"
                wire:model="email"
                autocomplete="email"
                class="claim-input"
                style="width:100%;box-sizing:border-box;padding:0.625rem 0.75rem;border:1px solid #d1d5db;border-radius:0.5rem;font-size:0.95rem;"
            >
            @error('email')
                <p style="margin:0.375rem 0 0;color:#dc2626;font-size:0.8125rem;">{{ $message }}</p>
            @enderror

            @if ($lookupMessage)
                <div class="claim-message" style="margin-top:1rem;padding:0.75rem 1rem;border:1px solid #fcd34d;background-color:#fffbeb;border-radius:0.5rem;color:#92400e;font-size:0.875rem;">
                    {{ $lookupMessage }}
                    <a href="/account/login" wire:navigate style="color:inherit;font-weight:600;text-decoration:underline;">Go to sign in</a>
                </div>
            @endif

            <button
                type="submit"
                style="margin-top:1.5rem;width:100%;padding:0.625rem 1rem;border:none;border-radius:0.5rem;background-color:#f59e0b;color:#ffffff;font-size:0.95rem;font-weight:600;cursor:pointer;"
            >
                <span wire:loading.remove wire:target="lookup">Find my account</span>
                <span wire:loading wire:target="lookup">Searching…</span>
            </button>
        </form>
    @else
        <p class="claim-subtle" style="margin:0 0 1.5rem;color:#6b7280;">
            Welcome, <strong style="color:#111827;">{{ $memberName }}</strong> — {{ $memberGrade }}.
            Just two quick steps to finish setting up.
        </p>

        {{ $this->claimForm }}
    @endif
</div>
