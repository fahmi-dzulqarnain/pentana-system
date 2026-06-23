<div style="background-color:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.1);padding:2rem;" wire:key="claim-card">
    <style>
        .dark .claim-card { background-color: #18181b; }
        .dark .claim-title { color: #fafafa; }
        .dark .claim-subtle { color: #a1a1aa; }
        .dark .claim-input { background-color: #27272a; border-color: #3f3f46; color: #fafafa; }
        .dark .claim-message { background-color: #422006; border-color: #854d0e; color: #fde68a; }
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
