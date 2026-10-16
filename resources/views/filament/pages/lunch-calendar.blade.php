<x-filament-panels::page>
    {{-- Dark-mode-aware styling. Filament's precompiled CSS can't carry `dark:` utilities and
         its palette vars (--white/--gray-*) don't flip, so custom colors live here with `.dark`
         overrides (the `dark` class is toggled on <html> by Filament). --}}
    <style>
        .lc-nav-btn { display:inline-flex; align-items:center; justify-content:center; padding:0.375rem 0.625rem; border-radius:0.5rem; border:1px solid rgb(0 0 0 / 0.1); background:#fff; color:#374151; cursor:pointer; }
        .lc-nav-btn--text { padding:0.375rem 0.875rem; font-size:0.875rem; }
        .lc-nav-btn:hover { background:#f9fafb; }
        .dark .lc-nav-btn { background:rgb(255 255 255 / 0.05); border-color:rgb(255 255 255 / 0.1); color:#e5e7eb; }
        .dark .lc-nav-btn:hover { background:rgb(255 255 255 / 0.1); }

        .lc-month { font-size:1.125rem; font-weight:600; color:#111827; min-width:10rem; text-align:center; }
        .dark .lc-month { color:#f9fafb; }

        .lc-weekday { text-align:center; font-size:0.75rem; font-weight:600; color:#6b7280; padding:0.375rem 0; text-transform:uppercase; letter-spacing:0.05em; }
        .dark .lc-weekday { color:#9ca3af; }

        .lc-cell { min-height:7rem; background:#fff; border:1px solid #e5e7eb; border-radius:0.5rem; padding:0.5rem; overflow:hidden; }
        .lc-cell--out { background:#f9fafb; }
        .lc-cell--today { border-color:var(--primary-500,#f59e0b); border-width:2px; }
        .dark .lc-cell { background:rgb(255 255 255 / 0.05); border-color:rgb(255 255 255 / 0.1); }
        .dark .lc-cell--out { background:rgb(255 255 255 / 0.02); }

        .lc-daynum { font-size:0.8125rem; font-weight:500; color:#374151; margin-bottom:0.375rem; }
        .lc-daynum--out { color:#9ca3af; }
        .dark .lc-daynum { color:#d1d5db; }
        .dark .lc-daynum--out { color:#6b7280; }
        .lc-daynum--today { display:inline-flex; align-items:center; justify-content:center; width:1.5rem; height:1.5rem; background:var(--primary-600,#d97706); color:#fff; border-radius:9999px; font-weight:700; }

        .lc-menucard { display:block; text-decoration:none; padding:0.375rem 0.5rem; background:var(--primary-50,#fffbeb); border:1px solid var(--primary-200,#fde68a); border-radius:0.375rem; margin-top:0.25rem; }
        .lc-menucard__title { font-size:0.75rem; font-weight:600; color:var(--primary-700,#b45309); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:0.25rem; }
        .dark .lc-menucard { background:rgb(245 158 11 / 0.12); border-color:rgb(245 158 11 / 0.3); }
        .dark .lc-menucard__title { color:#fcd34d; }
        .lc-menucard__menu + .lc-menucard__menu { margin-top:0.375rem; padding-top:0.375rem; border-top:1px solid var(--primary-200,#fde68a); }
        .dark .lc-menucard__menu + .lc-menucard__menu { border-color:rgb(245 158 11 / 0.3); }
        .lc-menucard__caterer { font-size:0.6875rem; color:#6b7280; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin:-0.125rem 0 0.25rem; }
        .dark .lc-menucard__caterer { color:#9ca3af; }

        .lc-setmenu { display:block; text-decoration:none; margin-top:0.25rem; padding:0.25rem 0; font-size:0.75rem; color:#6b7280; text-align:center; }
        .lc-setmenu:hover { color:#374151; }
        .dark .lc-setmenu { color:#9ca3af; }
        .dark .lc-setmenu:hover { color:#d1d5db; }

        .lc-stat-hint { font-size:0.75rem; color:#6b7280; margin-top:0.125rem; }
        .dark .lc-stat-hint { color:#9ca3af; }

        .lc-notice { display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap; padding:0.875rem 1rem; margin-bottom:1.25rem; border-radius:0.75rem; border:1px solid var(--primary-200,#fde68a); background:var(--primary-50,#fffbeb); color:#78350f; font-size:0.875rem; }
        .lc-notice a { font-weight:600; color:inherit; }
        .dark .lc-notice { background:rgb(245 158 11 / 0.12); border-color:rgb(245 158 11 / 0.3); color:#fde68a; }

        .lc-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:1rem; margin-bottom:1.5rem; }

        /* Phones: seven columns can't fit, so the month becomes a list of days. */
        .lc-agenda { display:none; }
        .lc-agenda__day { display:flex; gap:0.75rem; align-items:flex-start; padding:0.75rem 0; border-top:1px solid #e5e7eb; }
        .lc-agenda__day:first-child { border-top:0; }
        .dark .lc-agenda__day { border-color:rgb(255 255 255 / 0.1); }
        .lc-agenda__date { flex:0 0 3.25rem; text-align:center; color:#374151; }
        .dark .lc-agenda__date { color:#d1d5db; }
        .lc-agenda__dow { font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; }
        .dark .lc-agenda__dow { color:#9ca3af; }
        .lc-agenda__num { font-size:1.25rem; font-weight:600; line-height:1.4; }
        .lc-agenda__day--today .lc-agenda__num { color:var(--primary-600,#d97706); }
        .lc-agenda__body { flex:1; min-width:0; }
        .lc-agenda__body .lc-menucard { margin-top:0; }
        .lc-agenda__set { display:flex; align-items:center; min-height:2.75rem; padding:0 0.875rem; border:1px dashed #d1d5db; border-radius:0.5rem; color:#4b5563; font-size:0.875rem; text-decoration:none; }
        .dark .lc-agenda__set { border-color:rgb(255 255 255 / 0.2); color:#d1d5db; }

        @media (max-width: 639px) {
            .lc-stats { grid-template-columns:1fr; gap:0.75rem; }
            .lc-grid { display:none; }
            .lc-agenda { display:block; }
        }
    </style>

    {{-- Navigation header: prev / month label / next / Today --}}
    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;flex-wrap:wrap;">
        <button wire:click="previousMonth" class="lc-nav-btn" type="button">
            <x-heroicon-o-chevron-left style="width:1rem;height:1rem;" />
        </button>

        <span class="lc-month">{{ $this->monthLabel() }}</span>

        <button wire:click="nextMonth" class="lc-nav-btn" type="button">
            <x-heroicon-o-chevron-right style="width:1rem;height:1rem;" />
        </button>

        <button wire:click="goToday" class="lc-nav-btn lc-nav-btn--text" style="margin-left:0.25rem;" type="button">
            {{ __('Today') }}
        </button>
    </div>

    @unless($this->hasCaterers())
        {{-- A lunch needs a caterer's menu, so planning dead-ends until one exists. --}}
        <div class="lc-notice" role="status">
            <x-heroicon-o-information-circle style="width:1.25rem;height:1.25rem;flex:none;" />
            <span>{{ __('Add a caterer and their menu before planning lunches.') }}</span>
            <a href="{{ \App\Filament\Resources\Caterers\CatererResource::getUrl('create') }}">{{ __('Add a caterer') }} →</a>
        </div>
    @endunless

    {{-- Stat cards --}}
    <div class="lc-stats">
        <x-filament::section>
            <div style="text-align:center;padding:0.5rem 0;">
                <div style="font-size:1.75rem;font-weight:700;color:var(--primary-600,#d97706);line-height:1.2;">
                    {{ $this->daysPlanned() }} / {{ $this->workingDays() }}
                </div>
                <div style="font-size:0.8125rem;color:var(--gray-500,#6b7280);margin-top:0.25rem;font-weight:500;">{{ __('Days planned') }}</div>
                <div class="lc-stat-hint">{{ __('working days this month') }}</div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div style="text-align:center;padding:0.5rem 0;">
                <div style="font-size:1.75rem;font-weight:700;color:var(--primary-600,#d97706);line-height:1.2;">
                    {{ number_format($this->avgOrdersPerDay(), 0) }}
                </div>
                <div style="font-size:0.8125rem;color:var(--gray-500,#6b7280);margin-top:0.25rem;font-weight:500;">{{ __('Average orders per day') }}</div>
                <div class="lc-stat-hint">{{ __('published days this month') }}</div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div style="text-align:center;padding:0.5rem 0;">
                <div style="font-size:1.75rem;font-weight:700;color:var(--primary-600,#d97706);line-height:1.2;">
                    MYR {{ number_format($this->estimatedCost(), 2) }}
                </div>
                <div style="font-size:0.8125rem;color:var(--gray-500,#6b7280);margin-top:0.25rem;font-weight:500;">{{ __('Estimated cost') }}</div>
                <div class="lc-stat-hint">{{ __('this month') }}</div>
            </div>
        </x-filament::section>
    </div>

    {{-- Calendar grid (tablet and up) --}}
    <x-filament::section class="lc-grid">
        {{-- Weekday headers --}}
        <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:1px;margin-bottom:0.5rem;">
            {{-- Sun..Sat, from Carbon so the abbreviations follow the locale. --}}
            @foreach(range(0, 6) as $offset)
                <div class="lc-weekday">{{ \Carbon\Carbon::now()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->addDays($offset)->translatedFormat('D') }}</div>
            @endforeach
        </div>

        {{-- Calendar weeks --}}
        <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;">
            @foreach($this->calendarWeeks() as $week)
                @foreach($week as $day)
                    @php
                        $isToday = $day['date']->isToday();
                        $isWeekend = $day['date']->isWeekend();
                        $inMonth = $day['inMonth'];
                        $lunch = $day['lunch'];
                    @endphp
                    <div @class(['lc-cell', 'lc-cell--out' => ! $inMonth, 'lc-cell--today' => $isToday])>
                        {{-- Day number --}}
                        <div @class([
                            'lc-daynum',
                            'lc-daynum--out' => ! $inMonth,
                            'lc-daynum--today' => $isToday,
                        ])>
                            {{ $day['date']->day }}
                        </div>

                        @if($lunch)
                            {{-- Menu card --}}
                            <a href="{{ $this->editUrlFor($lunch) }}" class="lc-menucard">
                            @foreach($lunch->menus as $menu)
                                <div class="lc-menucard__menu">
                                    <div class="lc-menucard__title">{{ $menu->catererMeal?->name ?? __('Menu') }}</div>
                                    @if($menu->caterer)
                                        <div class="lc-menucard__caterer">{{ $menu->caterer->name }}</div>
                                    @endif
                                    <div style="display:flex;flex-wrap:wrap;gap:0.25rem;">
                                        @foreach($menu->options->where('enabled', true) as $option)
                                            @if($option->mealOption)
                                                <x-filament::badge color="primary" size="sm">{{ $option->mealOption->name }}</x-filament::badge>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                            @if(in_array($lunch->status, ['published', 'closed'], true))
                                <div style="font-size:0.6875rem;color:var(--gray-500,#6b7280);margin-top:0.25rem;font-weight:600;">{{ __(':count ordered', ['count' => $lunch->attendingCount()]) }}</div>
                            @endif
                            </a>
                        @elseif($inMonth && ! $isWeekend)
                            {{-- Empty weekday: set menu link --}}
                            <a href="{{ $this->createUrlFor($day['date']) }}" class="lc-setmenu">+ {{ __('Set menu') }}</a>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    </x-filament::section>

    {{-- Agenda (phones): the same days, one per row. Weekends appear only when they have a lunch. --}}
    <x-filament::section class="lc-agenda">
        @foreach(collect($this->calendarWeeks())->flatten(1)->filter(fn ($day) => $day['inMonth'] && ($day['lunch'] || ! $day['date']->isWeekend())) as $day)
            @php $lunch = $day['lunch']; @endphp
            <div @class(['lc-agenda__day', 'lc-agenda__day--today' => $day['date']->isToday()])>
                <div class="lc-agenda__date">
                    <div class="lc-agenda__dow">{{ $day['date']->translatedFormat('D') }}</div>
                    <div class="lc-agenda__num">{{ $day['date']->day }}</div>
                </div>
                <div class="lc-agenda__body">
                    @if($lunch)
                        <a href="{{ $this->editUrlFor($lunch) }}" class="lc-menucard">
                        @foreach($lunch->menus as $menu)
                            <div class="lc-menucard__menu">
                                <div class="lc-menucard__title">{{ $menu->catererMeal?->name ?? __('Menu') }}</div>
                                @if($menu->caterer)
                                    <div class="lc-menucard__caterer">{{ $menu->caterer->name }}</div>
                                @endif
                                <div style="display:flex;flex-wrap:wrap;gap:0.25rem;">
                                    @foreach($menu->options->where('enabled', true) as $option)
                                        @if($option->mealOption)
                                            <x-filament::badge color="primary" size="sm">{{ $option->mealOption->name }}</x-filament::badge>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                        @if(in_array($lunch->status, ['published', 'closed'], true))
                            <div style="font-size:0.75rem;color:var(--gray-500,#6b7280);margin-top:0.25rem;font-weight:600;">{{ __(':count ordered', ['count' => $lunch->attendingCount()]) }}</div>
                        @endif
                        </a>
                    @else
                        <a href="{{ $this->createUrlFor($day['date']) }}" class="lc-agenda__set">+ {{ __('Set menu') }}</a>
                    @endif
                </div>
            </div>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
