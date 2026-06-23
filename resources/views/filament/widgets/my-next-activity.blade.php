<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Your next activity</x-slot>

        @php($activity = $this->nextActivity())

        @if ($activity)
            <div style="display:flex; flex-direction:column; gap:0.25rem;">
                <div style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--primary-600,#d97706);">
                    {{ $activity->starts_at->format('l, j M · g:i A') }}
                </div>
                <div style="font-size:1.05rem; font-weight:700;">{{ $activity->title }}</div>
                @if ($activity->location)
                    <div style="font-size:0.8125rem; color:#6b7280;">📍 {{ $activity->location }}</div>
                @endif
                <a href="{{ \App\Filament\Pages\MyActivities::getUrl() }}" style="margin-top:0.5rem; font-size:0.875rem; font-weight:600; color:var(--primary-600,#d97706); text-decoration:none;">
                    View all activities →
                </a>
            </div>
        @else
            <div style="font-size:0.875rem; color:#6b7280;">
                You have no upcoming activities.
                <a href="{{ \App\Filament\Pages\MyActivities::getUrl() }}" style="font-weight:600; color:var(--primary-600,#d97706); text-decoration:none;">Browse activities →</a>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
