<div>
    {{-- Filament's precompiled CSS has no arbitrary utilities, so the few custom styles live here, with `.dark` overrides. --}}
    <style>
        .lv-summary { font-size:0.875rem; color:#374151; margin:0 0 0.75rem; }
        .lv-actions { display:flex; gap:0.5rem; flex-wrap:wrap; }
        .lv-muted { font-size:0.75rem; color:#6b7280; margin:0.5rem 0 0; }
        .lv-menu { font-size:0.9375rem; font-weight:600; color:#111827; margin:1.25rem 0 0.375rem; }
        .lv-menu span { font-weight:400; color:#6b7280; }
        .lv-group { font-size:0.875rem; font-weight:600; color:#111827; margin:1.25rem 0 0.375rem; }
        .lv-row { display:flex; gap:0.75rem; font-size:0.875rem; padding:0.25rem 0; }
        .lv-row dt { flex:0 0 9rem; font-weight:600; color:#111827; }
        .lv-names { display:flex; flex-wrap:wrap; gap:0.25rem 0.75rem; align-items:center; font-size:0.875rem; color:#374151; }
        .lv-name { display:inline-flex; gap:0.25rem; align-items:center; }
        .lv-name-btn { color:inherit; text-decoration:underline dotted; text-underline-offset:3px; cursor:pointer; }
        .lv-nobody { color:#9ca3af; font-style:italic; }
        .dark .lv-summary, .dark .lv-names { color:#d1d5db; }
        .dark .lv-menu, .dark .lv-group, .dark .lv-row dt { color:#f9fafb; }
        .dark .lv-menu span, .dark .lv-muted { color:#9ca3af; }
        .dark .lv-nobody { color:#6b7280; }
    </style>

    <x-filament::section :heading="__('Current orders')">
        <p class="lv-summary">
            {{ __('Ordering :ordering · Not attending :not_attending · Not responded :not_responded', $breakdown['counts']) }}
            @if ($lunch->deadline)
                &nbsp;·&nbsp; {{ __('Deadline :time', ['time' => $lunch->deadline->translatedFormat('D j M, '.\App\Support\TimeFormat::time())]) }}
            @endif
        </p>

        <div class="lv-actions">
            <x-filament::button tag="a" :href="route('lunches.print', $lunch)" target="_blank" color="gray" icon="heroicon-o-printer" size="sm">
                {{ __('Print list') }}
            </x-filament::button>

            @if ($canRemind && $breakdown['counts']['not_responded'] > 0)
                {{-- The button disables itself while the request runs (Filament adds wire:loading.attr), so a double click can't double-send. --}}
                <x-filament::button
                    wire:click="remind"
                    wire:confirm="{{ __('Send the last-call reminder to :count members who haven\'t responded?', ['count' => $breakdown['counts']['not_responded']]) }}"
                    icon="heroicon-o-bell"
                    size="sm"
                >
                    {{ __('Remind :count non-voters', ['count' => $breakdown['counts']['not_responded']]) }}
                </x-filament::button>
            @endif
        </div>

        @if ($lunch->manual_reminder_sent_at)
            <p class="lv-muted">
                {{ __('Last reminder sent: :time by :name', [
                    'time' => $lunch->manual_reminder_sent_at->translatedFormat('D j M, '.\App\Support\TimeFormat::time()),
                    'name' => $lunch->manualReminderSender?->name ?? __('a deleted admin'),
                ]) }}
            </p>
        @endif

        @foreach ($breakdown['menus'] as $menu)
            <h3 class="lv-menu">{{ $menu['meal'] }}@if ($menu['caterer']) <span>· {{ $menu['caterer'] }}</span>@endif</h3>
            <dl>
                @foreach ($menu['options'] as $option)
                    <div class="lv-row">
                        <dt>{{ $option['name'] }} ({{ count($option['members']) }})</dt>
                        <dd>@include('livewire.admin.lunch-votes-names', ['members' => $option['members']])</dd>
                    </div>
                @endforeach
            </dl>
        @endforeach

        <h3 class="lv-group">{{ __('Not attending (:count)', ['count' => $breakdown['counts']['not_attending']]) }}</h3>
        @include('livewire.admin.lunch-votes-names', ['members' => $breakdown['not_attending']])

        <h3 class="lv-group">{{ __('Not responded (:count)', ['count' => $breakdown['counts']['not_responded']]) }}</h3>
        @include('livewire.admin.lunch-votes-names', ['members' => $breakdown['not_responded']])
    </x-filament::section>
</div>
