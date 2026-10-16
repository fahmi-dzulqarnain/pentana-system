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
        .ma-answers { margin-top:0.625rem; font-size:0.8125rem; color:#6b7280; }
        .dark .ma-answers { color:#9ca3af; }
        .ma-answers strong { color:#374151; font-weight:600; }
        .dark .ma-answers strong { color:#e5e7eb; }
        .ma-field { margin-bottom:0.875rem; }
        .ma-field > label { display:block; font-size:0.8125rem; font-weight:600; color:#374151; margin-bottom:0.25rem; }
        .dark .ma-field > label { color:#e5e7eb; }
        .ma-input { width:100%; box-sizing:border-box; padding:0.5rem 0.625rem; border:1px solid #d1d5db; border-radius:0.5rem; font-size:0.875rem; background:#fff; color:#111827; }
        .dark .ma-input { background:#27272a; border-color:#3f3f46; color:#fafafa; }
        .ma-req { color:#dc2626; }
        .ma-err { color:#dc2626; font-size:0.75rem; margin-top:0.25rem; }
        .ma-modal-overlay { position:fixed; inset:0; background:rgb(0 0 0 / 0.5); display:flex; align-items:center; justify-content:center; padding:1.5rem; z-index:50; }
        .ma-modal { background:#fff; border-radius:0.75rem; padding:1.5rem; width:100%; max-width:32rem; max-height:85vh; overflow-y:auto; }
        .dark .ma-modal { background:#18181b; }
        .ma-modal h3 { font-size:1.125rem; font-weight:700; margin:0 0 1rem; color:#111827; }
        .dark .ma-modal h3 { color:#fafafa; }
        .ma-modal-actions { display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem; }
    </style>

    @forelse($this->upcomingActivities() as $activity)
        @php($status = $this->statusFor($activity))
        <div class="ma-card">
            <div class="ma-date">{{ $activity->starts_at->translatedFormat('l, j M · g:i A') }}</div>
            <div class="ma-title">{{ $activity->title }}</div>
            @if($activity->location)
                <div class="ma-meta">📍 {{ $activity->location }}</div>
            @endif
            @if($activity->description)
                {{-- Admin-authored RichEditor HTML (trusted), rendered like the email body. --}}
                <div class="ma-desc">{!! str($activity->description)->sanitizeHtml() !!}</div>
            @endif

            <div class="ma-foot">
                @if($status === 'registered')
                    <span class="ma-badge ma-badge--ok">{{ __('Registered') }}</span>
                    <button type="button" class="ma-btn" wire:click="cancelRegistration({{ $activity->id }})">{{ __('Cancel') }}</button>
                @elseif($status === 'waitlisted')
                    <span class="ma-badge ma-badge--wait">{{ __('Waitlisted #:position', ['position' => $this->waitlistPositionFor($activity)]) }}</span>
                    <button type="button" class="ma-btn" wire:click="cancelRegistration({{ $activity->id }})">{{ __('Cancel') }}</button>
                @elseif($activity->isFull())
                    <span class="ma-meta">{{ __('Full') }}</span>
                    <button type="button" class="ma-btn ma-btn--primary" wire:click="startRegistration({{ $activity->id }})">{{ __('Join waitlist') }}</button>
                @else
                    <span class="ma-meta">
                        {{ $activity->spotsLeft() === null
                            ? __('Open')
                            : trans_choice(':count spot left|:count spots left', $activity->spotsLeft()) }}
                    </span>
                    <button type="button" class="ma-btn ma-btn--primary" wire:click="startRegistration({{ $activity->id }})">{{ __('Register') }}</button>
                @endif
            </div>

            @if(in_array($status, ['registered', 'waitlisted'], true) && $activity->hasRegistrationFields())
                @php($mine = $this->myRegistrationFor($activity))
                @if($mine && $mine->answers)
                    <div class="ma-answers">
                        @foreach($activity->registration_fields as $field)
                            @php($val = $mine->answers[$field['key']] ?? null)
                            @if($val !== null && $val !== '')
                                <div><strong>{{ $field['label'] }}:</strong> {{ is_bool($val) ? ($val ? __('Yes') : __('No')) : $val }}</div>
                            @endif
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    @empty
        <div class="ma-empty">{{ __('No upcoming activities right now.') }}</div>
    @endforelse

    @if($this->pastActivities()->isNotEmpty())
        <h3 class="ma-section">{{ __('Past activities') }}</h3>
        @foreach($this->pastActivities() as $activity)
            @php($mine = $activity->registrations->first())
            <div class="ma-card">
                <div class="ma-date">{{ $activity->starts_at->translatedFormat('l, j M') }}</div>
                <div class="ma-title">{{ $activity->title }}</div>
                <div class="ma-foot">
                    @if($mine?->attended)
                        <span class="ma-badge ma-badge--ok">{{ __('Attended') }}</span>
                    @else
                        <span class="ma-badge">{{ __('Registered') }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    @endif

    {{-- Registration questions modal (only for activities that have questions) --}}
    @if($this->registeringActivityId)
        <div class="ma-modal-overlay" wire:key="register-modal">
            <div class="ma-modal">
                <h3>{{ __('A few quick questions') }}</h3>
                @foreach($this->registeringFields() as $field)
                    <div class="ma-field">
                        <label>
                            {{ $field['label'] }}@if($field['required'] ?? false) <span class="ma-req">*</span>@endif
                        </label>
                        @if(($field['type'] ?? 'text') === 'textarea')
                            <textarea class="ma-input" rows="3" wire:model="answers.{{ $field['key'] }}"></textarea>
                        @elseif(($field['type'] ?? 'text') === 'select')
                            <select class="ma-input" wire:model="answers.{{ $field['key'] }}">
                                <option value="">{{ __('Choose an option') }}</option>
                                @foreach($field['options'] ?? [] as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                        @elseif(($field['type'] ?? 'text') === 'checkbox')
                            <label style="font-weight:500;display:inline-flex;align-items:center;gap:0.5rem;">
                                <input type="checkbox" wire:model="answers.{{ $field['key'] }}"> {{ __('Yes') }}
                            </label>
                        @else
                            <input type="text" class="ma-input" wire:model="answers.{{ $field['key'] }}">
                        @endif
                        @error('answers.'.$field['key'])
                            <div class="ma-err">{{ $message }}</div>
                        @enderror
                    </div>
                @endforeach
                <div class="ma-modal-actions">
                    <button type="button" class="ma-btn" wire:click="closeRegistration">{{ __('Cancel') }}</button>
                    <button type="button" class="ma-btn ma-btn--primary" wire:click="submitRegistration">{{ __('Confirm registration') }}</button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
