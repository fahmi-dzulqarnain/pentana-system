{{-- Inline styles: Filament's precompiled CSS doesn't ship arbitrary Tailwind utilities. --}}
<div style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;">
    <img
        src="{{ $url }}"
        alt="{{ __('Receipt proof') }}"
        style="max-height:75vh;max-width:100%;height:auto;border-radius:0.5rem;"
    />

    <a
        href="{{ $url }}"
        target="_blank"
        rel="noopener"
        style="font-size:0.875rem;font-weight:500;text-decoration:underline;"
    >
        {{ __('Open original') }} &#8599;
    </a>
</div>
