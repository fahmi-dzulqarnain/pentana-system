<x-filament-panels::page>
    {{-- Inline + .dark styling: Filament's precompiled CSS can't carry arbitrary Tailwind/dark: utilities. --}}
    <style>
        .ml-wrap { max-width: 44rem; }
        .ml-banner { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem 1rem; padding: 0.875rem 1rem; border-radius: 0.875rem; background: #fdf1e4; margin-bottom: 1.25rem; }
        .dark .ml-banner { background: rgb(245 158 11 / 0.14); }
        .ml-banner-text { flex: 1 1 16rem; min-width: 0; }
        .ml-banner-title { font-weight: 600; font-size: 0.9375rem; }
        .ml-banner-line { font-size: 0.8125rem; }
        .ml-section { font-size: 0.75rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: #6b7280; margin: 1.25rem 0.25rem 0.5rem; }
        .dark .ml-section { color: #9ca3af; }
        .ml-group { background: #fff; border: 1px solid #e5e7eb; border-radius: 0.875rem; overflow: hidden; }
        .dark .ml-group { background: rgb(255 255 255 / 0.05); border-color: rgb(255 255 255 / 0.1); }
        .ml-row { display: flex; width: 100%; align-items: center; gap: 0.875rem; padding: 0.75rem 1rem; text-align: left; background: transparent; border: 0; cursor: pointer; color: inherit; font: inherit; }
        .ml-row + .ml-row { border-top: 1px solid #f0f0f3; }
        .dark .ml-row + .ml-row { border-top-color: rgb(255 255 255 / 0.08); }
        .ml-row:hover { background: #fafafa; }
        .dark .ml-row:hover { background: rgb(255 255 255 / 0.04); }
        .ml-row:focus-visible { outline: 2px solid #b45309; outline-offset: -2px; }
        .ml-row--today { background: #fdf1e4; padding-top: 1rem; padding-bottom: 1rem; }
        .ml-row--today:hover { background: #fbe7d2; }
        .dark .ml-row--today { background: rgb(245 158 11 / 0.14); }
        .dark .ml-row--today:hover { background: rgb(245 158 11 / 0.2); }
        .ml-date { width: 3rem; flex-shrink: 0; text-align: center; line-height: 1.15; }
        .ml-date-wd { font-size: 0.6875rem; font-weight: 600; color: #6b7280; }
        .dark .ml-date-wd { color: #9ca3af; }
        .ml-date-d { font-size: 1.25rem; font-weight: 600; }
        .ml-body { flex: 1; min-width: 0; }
        .ml-eyebrow { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; }
        .ml-title { font-weight: 500; }
        .ml-title--strong { font-weight: 600; }
        .ml-title--big { font-size: 1.1875rem; font-weight: 600; }
        .ml-detail { color: #6b7280; font-weight: 400; }
        .dark .ml-detail { color: #9ca3af; }
        .ml-sub { font-size: 0.8125rem; color: #6b7280; }
        .dark .ml-sub { color: #9ca3af; }
        .ml-sub--urgent { font-weight: 600; }
        .ml-preview { font-size: 0.8125rem; color: #9ca3af; }
        .ml-past { padding-top: 0.625rem; padding-bottom: 0.625rem; color: #6b7280; }
        .dark .ml-past { color: #9ca3af; }
        .ml-past .ml-date { font-size: 0.75rem; font-weight: 600; white-space: nowrap; }
        .ml-past .ml-body { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ml-pill { flex-shrink: 0; display: inline-flex; align-items: center; min-height: 2.25rem; padding: 0 1rem; border-radius: 999px; background: #f59e0b; color: #2a1b00; font-weight: 600; font-size: 0.875rem; }
        .ml-link { flex-shrink: 0; font-weight: 600; font-size: 0.875rem; }
        .ml-chev { flex-shrink: 0; color: #c4c4cc; }
        .ml-thumb { width: 3rem; height: 3rem; flex-shrink: 0; border-radius: 0.5rem; object-fit: cover; }
        .ml-foot { text-align: center; font-size: 0.875rem; color: #6b7280; margin-top: 1rem; }
        .dark .ml-foot { color: #9ca3af; }
        /* Vote panel */
        .ml-menu { border: 1px solid #e5e7eb; border-radius: 0.75rem; overflow: hidden; margin-bottom: 0.875rem; }
        .dark .ml-menu { border-color: rgb(255 255 255 / 0.1); }
        .ml-menu-head { display: flex; align-items: center; gap: 0.75rem; padding: 0.625rem 0.875rem; font-size: 0.8125rem; font-weight: 600; color: #4b5563; background: #f9fafb; }
        .dark .ml-menu-head { color: #d1d5db; background: rgb(255 255 255 / 0.04); }
        .ml-opt { display: flex; width: 100%; align-items: center; gap: 0.75rem; padding: 0.75rem 0.875rem; border: 0; border-top: 1px solid #f0f0f3; background: transparent; color: inherit; font: inherit; text-align: left; cursor: pointer; }
        .dark .ml-opt { border-top-color: rgb(255 255 255 / 0.08); }
        .ml-opt:hover { background: #fafafa; }
        .dark .ml-opt:hover { background: rgb(255 255 255 / 0.04); }
        .ml-opt:focus-visible { outline: 2px solid #b45309; outline-offset: -2px; }
        .ml-opt--on { background: #fdf1e4; font-weight: 600; }
        .dark .ml-opt--on { background: rgb(245 158 11 / 0.14); }
        .ml-opt--solo { border-top: 0; }
        .ml-radio { margin-left: auto; width: 1.375rem; height: 1.375rem; flex-shrink: 0; border-radius: 999px; border: 1.5px solid #c4c4cc; display: grid; place-items: center; }
        .ml-opt--on .ml-radio { background: #f59e0b; border-color: #f59e0b; color: #fff; }
        .ml-note { font-size: 0.8125rem; color: #6b7280; margin: 0.25rem 0.25rem 0.75rem; }
        .dark .ml-note { color: #9ca3af; }
        .ml-summary { display: flex; gap: 0.5rem; align-items: flex-start; padding: 0.875rem; border-radius: 0.75rem; background: #f3f4f6; font-size: 0.875rem; margin-bottom: 0.875rem; }
        .dark .ml-summary { background: rgb(255 255 255 / 0.06); }
        .ml-busy { opacity: 0.6; pointer-events: none; }
        /* Accent text, last and doubled so it wins over the grey text classes above.
           #92400e on white is 7.1:1, on the banner tint 6.2:1 (WCAG AA); amber-600 was 3.2:1. */
        .ml-ink.ml-ink { color: #92400e; }
        .dark .ml-ink.ml-ink { color: #fbbf24; }
    </style>

    @php($agenda = $this->agenda())

    {{-- Re-render each minute while the tab is visible so countdowns tick and passed deadlines close.
         A light vibration confirms a saved pick on phones that support it (Android browsers). --}}
    <div class="ml-wrap" wire:poll.60s.visible
         x-data
         x-on:lunch-saved.window="navigator.vibrate && navigator.vibrate(15)"
         x-on:lunch-save-failed.window="navigator.vibrate && navigator.vibrate([30, 40, 30])">

        @if($agenda['sections'] === [])
            <x-empty-state
                icon="heroicon-o-cake"
                :heading="__('No lunch to order right now')"
                :description="__('When the next menu is published, it appears here and we will email you and notify you in the app.')"
                :action-url="auth()->user()?->is_admin ? \App\Filament\Pages\LunchCalendar::getUrl() : null"
                :action-label="__('Plan a lunch')"
            />
        @else
            @if($banner = $agenda['banner'])
                <div class="ml-banner" role="status">
                    <div class="ml-banner-text ml-ink">
                        <div class="ml-banner-title">{{ $banner['title'] }}</div>
                        @foreach($banner['lines'] as $line)
                            <div class="ml-banner-line">{{ $line }}</div>
                        @endforeach
                    </div>
                    <x-filament::button wire:click="openLunch({{ $banner['lunch_id'] }})">
                        {{ $banner['action'] }}
                    </x-filament::button>
                </div>
            @endif

            @foreach($agenda['sections'] as $section)
                <h2 class="ml-section">{{ $section['title'] }}</h2>
                <div class="ml-group">
                    @foreach($section['rows'] as $row)
                        @if($row['kind'] === 'past' && ! $row['is_today'])
                            {{-- A day before today: one dimmed line. --}}
                            <button type="button" class="ml-row ml-past" wire:click="openLunch({{ $row['lunch_id'] }})">
                                <span class="ml-date">{{ $row['weekday'] }} {{ $row['day'] }}</span>
                                <span class="ml-body">{{ $row['title'] }}@if($row['detail'])<span class="ml-detail"> · {{ $row['detail'] }}</span>@endif</span>
                                <x-filament::icon icon="heroicon-m-chevron-right" class="ml-chev h-5 w-5" />
                            </button>
                        @else
                            <button type="button" @class(['ml-row', 'ml-row--today' => $row['is_today']]) wire:click="openLunch({{ $row['lunch_id'] }})">
                                <span class="ml-date">
                                    <span @class(['ml-date-wd', 'ml-ink' => $row['is_today'] || $row['kind'] === 'needs_vote']) style="display:block">{{ $row['weekday'] }}</span>
                                    <span @class(['ml-date-d', 'ml-ink' => $row['is_today']]) style="display:block">{{ $row['day'] }}</span>
                                </span>
                                @if($row['image'])
                                    <img class="ml-thumb" src="{{ $row['image'] }}" alt="">
                                @endif
                                <span class="ml-body">
                                    @if($row['eyebrow'])
                                        <span class="ml-eyebrow ml-ink" style="display:block">{{ $row['eyebrow'] }}</span>
                                    @endif
                                    @if($row['is_today'])
                                        <span class="ml-title--big" style="display:block">{{ $row['title'] }}</span>
                                        @if($row['detail'])<span class="ml-sub" style="display:block">{{ $row['detail'] }}</span>@endif
                                    @else
                                        <span @class(['ml-title', 'ml-title--strong' => $row['kind'] === 'needs_vote']) style="display:block">{{ $row['title'] }}@if($row['detail'])<span class="ml-detail"> · {{ $row['detail'] }}</span>@endif</span>
                                    @endif
                                    @if($row['subtitle'])
                                        <span @class(['ml-sub', 'ml-sub--urgent ml-ink' => $row['urgent']]) style="display:block">{{ $row['subtitle'] }}</span>
                                    @endif
                                    @if($row['menu_preview'])
                                        <span class="ml-preview" style="display:block">{{ $row['menu_preview'] }}</span>
                                    @endif
                                </span>
                                @if($row['action'] === 'vote')
                                    <span class="ml-pill">{{ __('Vote') }}</span>
                                @elseif($row['action'] === 'change')
                                    <span class="ml-link ml-ink">{{ __('Change') }}</span>
                                @elseif(! $row['is_today'])
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="ml-chev h-5 w-5" />
                                @endif
                            </button>
                        @endif
                    @endforeach
                </div>
            @endforeach

            <p class="ml-foot">{{ __('No more lunches published yet') }}</p>
        @endif
    </div>

    <x-filament::modal id="lunch-vote" slide-over width="md" :close-by-clicking-away="true">
        @if($panel = $this->panel())
            <x-slot name="heading">{{ $panel['title'] }}</x-slot>
            <x-slot name="description">
                <span @class(['ml-sub--urgent ml-ink' => $panel['urgent']])>{{ $panel['status'] }}</span>
            </x-slot>

            <div wire:loading.class="ml-busy" wire:target="choose,notAttending,undo">
                @if($panel['open'])
                    @foreach($panel['menus'] as $menu)
                        <div class="ml-menu">
                            <div class="ml-menu-head">
                                @if($menu['image'])
                                    <img class="ml-thumb" src="{{ $menu['image'] }}" alt="">
                                @endif
                                <span>{{ $menu['heading'] }}</span>
                            </div>
                            @foreach($menu['options'] as $option)
                                <button type="button" @class(['ml-opt', 'ml-opt--on' => $option['selected']]) aria-pressed="{{ $option['selected'] ? 'true' : 'false' }}"
                                    wire:click="choose({{ $panel['lunch_id'] }}, {{ $option['id'] }})">
                                    <span>{{ $option['name'] }}</span>
                                    <span class="ml-radio">@if($option['selected'])<x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />@endif</span>
                                </button>
                            @endforeach
                        </div>
                    @endforeach

                    <div class="ml-menu">
                        <button type="button" @class(['ml-opt ml-opt--solo', 'ml-opt--on' => $panel['not_attending']]) aria-pressed="{{ $panel['not_attending'] ? 'true' : 'false' }}"
                            wire:click="notAttending({{ $panel['lunch_id'] }})">
                            <span>{{ __('Not attending') }}</span>
                            <span class="ml-radio">@if($panel['not_attending'])<x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />@endif</span>
                        </button>
                    </div>

                    <p class="ml-note">{{ __('Your pick saves as soon as you tap it. You can change it until the deadline.') }}</p>

                    @if($panel['next'])
                        <x-filament::button color="gray" outlined wire:click="openLunch({{ $panel['next']['lunch_id'] }})" icon="heroicon-m-chevron-right" icon-position="after" class="w-full">
                            {{ $panel['next']['label'] }}
                        </x-filament::button>
                    @endif
                @else
                    {{-- A closed day shows just what happened and what was on offer. --}}
                    <div class="ml-summary">
                        <x-filament::icon icon="heroicon-m-lock-closed" class="h-4 w-4" style="flex-shrink:0;margin-top:0.125rem" />
                        <span>{{ $panel['summary'] }}</span>
                    </div>
                    @foreach($panel['menus'] as $menu)
                        <div class="ml-menu">
                            <div class="ml-menu-head">
                                @if($menu['image'])
                                    <img class="ml-thumb" src="{{ $menu['image'] }}" alt="">
                                @endif
                                <span>{{ $menu['heading'] }}</span>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        @endif
    </x-filament::modal>
</x-filament-panels::page>
