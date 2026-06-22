<x-filament-panels::page>
    {{-- Inline + .dark styling: Filament's precompiled CSS can't carry arbitrary Tailwind/dark: utilities. --}}
    <style>
        .ml-card { background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.25rem; margin-bottom:1rem; }
        .dark .ml-card { background:rgb(255 255 255 / 0.05); border-color:rgb(255 255 255 / 0.1); }
        .ml-date { font-size:0.75rem; font-weight:600; color:var(--primary-600,#d97706); text-transform:uppercase; letter-spacing:0.05em; }
        .ml-menu { font-size:1.125rem; font-weight:700; color:#111827; margin-top:0.125rem; }
        .dark .ml-menu { color:#f9fafb; }
        .ml-meta { font-size:0.8125rem; color:#6b7280; margin-top:0.125rem; }
        .dark .ml-meta { color:#9ca3af; }
        .ml-opts { display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.875rem; }
        .ml-btn { display:inline-flex; align-items:center; padding:0.5rem 0.875rem; border-radius:0.5rem; border:1px solid rgb(0 0 0 / 0.1); background:#fff; color:#374151; cursor:pointer; font-size:0.875rem; font-weight:500; }
        .ml-btn:hover { background:#f9fafb; }
        .dark .ml-btn { background:rgb(255 255 255 / 0.05); border-color:rgb(255 255 255 / 0.1); color:#e5e7eb; }
        .dark .ml-btn:hover { background:rgb(255 255 255 / 0.1); }
        .ml-btn--active { background:var(--primary-600,#d97706); border-color:var(--primary-600,#d97706); color:#fff; }
        .dark .ml-btn--active { background:var(--primary-500,#f59e0b); border-color:var(--primary-500,#f59e0b); color:#1f2937; }
        .ml-locked { margin-top:0.75rem; font-size:0.875rem; color:#6b7280; }
        .dark .ml-locked { color:#9ca3af; }
        .ml-empty { text-align:center; padding:3rem 1rem; color:#6b7280; }
        .dark .ml-empty { color:#9ca3af; }
    </style>

    @php($responses = $this->myResponses())

    @forelse($this->upcomingLunches() as $lunch)
        @php($mine = $responses->get($lunch->id))
        <div class="ml-card">
            <div class="ml-date">{{ $lunch->date->format('l, j M') }}</div>
            <div class="ml-menu">{{ $lunch->catererMeal?->name ?? 'Menu' }}</div>
            <div class="ml-meta">{{ $lunch->caterer?->name }} &middot; Order by {{ $lunch->deadline->format('g:i A, D j M') }}</div>

            @if($lunch->isOpenForVoting())
                <div class="ml-opts">
                    @foreach($lunch->options->where('enabled', true) as $option)
                        @continue(! $option->mealOption)
                        <button type="button" wire:click="choose({{ $lunch->id }}, {{ $option->meal_option_id }})"
                            @class(['ml-btn', 'ml-btn--active' => $mine && $mine->meal_option_id === $option->meal_option_id])>
                            {{ $option->mealOption->name }}
                        </button>
                    @endforeach
                    <button type="button" wire:click="notAttending({{ $lunch->id }})"
                        @class(['ml-btn', 'ml-btn--active' => $mine && $mine->meal_option_id === null])>
                        Not attending
                    </button>
                </div>
            @else
                <div class="ml-locked">
                    Ordering closed.
                    @if($mine && $mine->meal_option_id)
                        You ordered: <strong>{{ $mine->mealOption?->name }}</strong>.
                    @elseif($mine)
                        You marked yourself not attending.
                    @else
                        No order was placed.
                    @endif
                </div>
            @endif
        </div>
    @empty
        <div class="ml-empty">No upcoming lunches to order right now.</div>
    @endforelse
</x-filament-panels::page>
