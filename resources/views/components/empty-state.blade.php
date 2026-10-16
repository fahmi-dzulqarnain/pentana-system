@props(['icon', 'heading', 'description', 'actionUrl' => null, 'actionLabel' => null])

{{-- Shown when a member's list is empty: what is (not) here, what happens next, and for
     admins the action that fills it. Inline styles + .dark overrides: Filament's
     precompiled CSS has no arbitrary utilities. --}}
<div class="pt-empty" role="status">
    <style>
        .pt-empty { display:flex; flex-direction:column; align-items:center; text-align:center; gap:0.5rem; padding:3rem 1.5rem; border:1px dashed #d1d5db; border-radius:0.75rem; background:#fff; }
        .dark .pt-empty { background:rgb(255 255 255 / 0.03); border-color:rgb(255 255 255 / 0.12); }
        .pt-empty__icon { display:flex; align-items:center; justify-content:center; width:3rem; height:3rem; border-radius:9999px; background:var(--primary-50,#fffbeb); color:var(--primary-600,#d97706); margin-bottom:0.25rem; }
        .dark .pt-empty__icon { background:rgb(245 158 11 / 0.12); color:#fcd34d; }
        .pt-empty__heading { margin:0; font-size:1rem; font-weight:600; color:#111827; }
        .dark .pt-empty__heading { color:#f9fafb; }
        .pt-empty__text { margin:0; max-width:28rem; font-size:0.875rem; line-height:1.5; color:#4b5563; }
        .dark .pt-empty__text { color:#d1d5db; }
    </style>

    <div class="pt-empty__icon">
        <x-filament::icon :icon="$icon" style="width:1.5rem;height:1.5rem;" />
    </div>
    <p class="pt-empty__heading">{{ $heading }}</p>
    <p class="pt-empty__text">{{ $description }}</p>

    @if ($actionUrl)
        <div style="margin-top:0.75rem;">
            <x-filament::button tag="a" :href="$actionUrl" icon="heroicon-m-plus">{{ $actionLabel }}</x-filament::button>
        </div>
    @endif
</div>
