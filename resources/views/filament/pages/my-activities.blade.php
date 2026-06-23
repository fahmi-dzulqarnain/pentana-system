<x-filament-panels::page>
    {{-- Inline + .dark styling: Filament's precompiled CSS can't carry arbitrary Tailwind/dark: utilities. --}}
    <style>
        .ma-card { background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.25rem; margin-bottom:1rem; }
        .dark .ma-card { background:rgb(255 255 255 / 0.05); border-color:rgb(255 255 255 / 0.1); }
        .ma-date { font-size:0.75rem; font-weight:600; color:var(--primary-600,#d97706); text-transform:uppercase; letter-spacing:0.05em; }
        .ma-title { font-size:1.125rem; font-weight:700; color:#111827; margin-top:0.125rem; }
        .dark .ma-title { color:#f9fafb; }
        .ma-meta { font-size:0.8125rem; color:#6b7280; margin-top:0.25rem; }
        .dark .ma-meta { color:#9ca3af; }
        .ma-desc { font-size:0.875rem; color:#374151; margin-top:0.5rem; }
        .dark .ma-desc { color:#d1d5db; }
        .ma-foot { display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center; margin-top:0.875rem; }
        .ma-btn { display:inline-flex; align-items:center; padding:0.5rem 0.875rem; border-radius:0.5rem; border:1px solid rgb(0 0 0 / 0.1); background:#fff; color:#374151; cursor:pointer; font-size:0.875rem; font-weight:500; }
        .ma-btn:hover { background:#f9fafb; }
        .dark .ma-btn { background:rgb(255 255 255 / 0.05); border-color:rgb(255 255 255 / 0.1); color:#e5e7eb; }
        .ma-btn--primary { background:var(--primary-600,#d97706); border-color:var(--primary-600,#d97706); color:#fff; }
        .ma-badge { display:inline-flex; align-items:center; padding:0.25rem 0.625rem; border-radius:9999px; font-size:0.75rem; font-weight:600; background:#f3f4f6; color:#374151; }
        .dark .ma-badge { background:rgb(255 255 255 / 0.1); color:#e5e7eb; }
        .ma-badge--ok { background:#dcfce7; color:#166534; }
        .ma-badge--wait { background:#fef9c3; color:#854d0e; }
        .ma-section { font-size:0.875rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:1.5rem 0 0.75rem; }
        .ma-empty { text-align:center; padding:3rem 1rem; color:#6b7280; }
        .dark .ma-empty { color:#9ca3af; }
    </style>

    @forelse($this->upcomingActivities() as $activity)
        @php($status = $this->statusFor($activity))
        <div class="ma-card">
            <div class="ma-date">{{ $activity->starts_at->format('l, j M · g:i A') }}</div>
            <div class="ma-title">{{ $activity->title }}</div>
            @if($activity->location)
                <div class="ma-meta">📍 {{ $activity->location }}</div>
            @endif
            @if($activity->description)
                {{-- Admin-authored RichEditor HTML (trusted), rendered like the email body. --}}
                <div class="ma-desc">{!! $activity->description !!}</div>
            @endif

            <div class="ma-foot">
                @if($status === 'registered')
                    <span class="ma-badge ma-badge--ok">Registered</span>
                    <button type="button" class="ma-btn" wire:click="cancelRegistration({{ $activity->id }})">Cancel</button>
                @elseif($status === 'waitlisted')
                    <span class="ma-badge ma-badge--wait">Waitlisted — #{{ $this->waitlistPositionFor($activity) }}</span>
                    <button type="button" class="ma-btn" wire:click="cancelRegistration({{ $activity->id }})">Cancel</button>
                @elseif($activity->isFull())
                    <span class="ma-meta">Full</span>
                    <button type="button" class="ma-btn ma-btn--primary" wire:click="register({{ $activity->id }})">Join waitlist</button>
                @else
                    <span class="ma-meta">
                        {{ $activity->spotsLeft() === null
                            ? 'Open'
                            : $activity->spotsLeft().' spot'.($activity->spotsLeft() === 1 ? '' : 's').' left' }}
                    </span>
                    <button type="button" class="ma-btn ma-btn--primary" wire:click="register({{ $activity->id }})">Register</button>
                @endif
            </div>
        </div>
    @empty
        <div class="ma-empty">No upcoming activities right now.</div>
    @endforelse

    @if($this->pastActivities()->isNotEmpty())
        <h3 class="ma-section">Past activities</h3>
        @foreach($this->pastActivities() as $activity)
            @php($mine = $activity->registrations->first())
            <div class="ma-card">
                <div class="ma-date">{{ $activity->starts_at->format('l, j M') }}</div>
                <div class="ma-title">{{ $activity->title }}</div>
                <div class="ma-foot">
                    @if($mine?->attended)
                        <span class="ma-badge ma-badge--ok">Attended</span>
                    @else
                        <span class="ma-badge">Registered</span>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
</x-filament-panels::page>
