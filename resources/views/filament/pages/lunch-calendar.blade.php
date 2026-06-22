<x-filament-panels::page>
    {{-- Navigation header: prev / month label / next / Today --}}
    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;flex-wrap:wrap;">
        <button wire:click="previousMonth"
            style="display:inline-flex;align-items:center;justify-content:center;padding:0.375rem 0.625rem;border-radius:0.375rem;border:1px solid var(--gray-300,#d1d5db);background:var(--white,#fff);color:var(--gray-700,#374151);cursor:pointer;"
            type="button">
            <x-heroicon-o-chevron-left style="width:1rem;height:1rem;" />
        </button>

        <span style="font-size:1.125rem;font-weight:600;color:var(--gray-900,#111827);min-width:10rem;text-align:center;">
            {{ $this->monthLabel() }}
        </span>

        <button wire:click="nextMonth"
            style="display:inline-flex;align-items:center;justify-content:center;padding:0.375rem 0.625rem;border-radius:0.375rem;border:1px solid var(--gray-300,#d1d5db);background:var(--white,#fff);color:var(--gray-700,#374151);cursor:pointer;"
            type="button">
            <x-heroicon-o-chevron-right style="width:1rem;height:1rem;" />
        </button>

        <button wire:click="goToday"
            style="margin-left:0.25rem;padding:0.375rem 0.875rem;border-radius:0.375rem;border:1px solid var(--gray-300,#d1d5db);background:var(--white,#fff);color:var(--gray-700,#374151);font-size:0.875rem;cursor:pointer;"
            type="button">
            Today
        </button>
    </div>

    {{-- Stat cards --}}
    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;margin-bottom:1.5rem;">

        {{-- Days Planned --}}
        <x-filament::section>
            <div style="text-align:center;padding:0.5rem 0;">
                <div style="font-size:1.75rem;font-weight:700;color:var(--primary-600,#4f46e5);line-height:1.2;">
                    {{ $this->daysPlanned() }} / {{ $this->workingDays() }}
                </div>
                <div style="font-size:0.8125rem;color:var(--gray-500,#6b7280);margin-top:0.25rem;font-weight:500;">
                    Days Planned
                </div>
                <div style="font-size:0.75rem;color:var(--gray-400,#9ca3af);margin-top:0.125rem;">
                    working days this month
                </div>
            </div>
        </x-filament::section>

        {{-- Avg. Orders/Day --}}
        <x-filament::section>
            <div style="text-align:center;padding:0.5rem 0;">
                <div style="font-size:1.75rem;font-weight:700;color:var(--primary-600,#4f46e5);line-height:1.2;">
                    &mdash;
                </div>
                <div style="font-size:0.8125rem;color:var(--gray-500,#6b7280);margin-top:0.25rem;font-weight:500;">
                    Avg. Orders/Day
                </div>
                <div style="font-size:0.75rem;color:var(--gray-400,#9ca3af);margin-top:0.125rem;">
                    available in Phase 3
                </div>
            </div>
        </x-filament::section>

        {{-- Estimated cost --}}
        <x-filament::section>
            <div style="text-align:center;padding:0.5rem 0;">
                <div style="font-size:1.75rem;font-weight:700;color:var(--primary-600,#4f46e5);line-height:1.2;">
                    MYR {{ number_format($this->estimatedCost(), 2) }}
                </div>
                <div style="font-size:0.8125rem;color:var(--gray-500,#6b7280);margin-top:0.25rem;font-weight:500;">
                    Estimated Cost
                </div>
                <div style="font-size:0.75rem;color:var(--gray-400,#9ca3af);margin-top:0.125rem;">
                    this month
                </div>
            </div>
        </x-filament::section>

    </div>

    {{-- Calendar grid --}}
    <x-filament::section>
        {{-- Weekday headers --}}
        <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:1px;margin-bottom:0.5rem;">
            @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dayName)
                <div style="text-align:center;font-size:0.75rem;font-weight:600;color:var(--gray-500,#6b7280);padding:0.375rem 0;text-transform:uppercase;letter-spacing:0.05em;">
                    {{ $dayName }}
                </div>
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
                        $bgColor = $inMonth ? 'var(--white,#fff)' : 'var(--gray-50,#f9fafb)';
                        $borderColor = $isToday ? 'var(--primary-500,#6366f1)' : 'var(--gray-200,#e5e7eb)';
                        $borderWidth = $isToday ? '2px' : '1px';
                        $dayNumColor = $inMonth ? ($isToday ? 'var(--primary-600,#4f46e5)' : 'var(--gray-700,#374151)') : 'var(--gray-400,#9ca3af)';
                    @endphp
                    <div style="min-height:7rem;background:{{ $bgColor }};border:{{ $borderWidth }} solid {{ $borderColor }};border-radius:0.5rem;padding:0.5rem;overflow:hidden;">
                        {{-- Day number --}}
                        <div style="font-size:0.8125rem;font-weight:{{ $isToday ? '700' : '500' }};color:{{ $dayNumColor }};margin-bottom:0.375rem;{{ $isToday ? 'display:inline-flex;align-items:center;justify-content:center;width:1.5rem;height:1.5rem;background:var(--primary-600,#4f46e5);color:#fff;border-radius:50%;' : '' }}">
                            {{ $day['date']->day }}
                        </div>

                        @if($lunch)
                            {{-- Menu card --}}
                            <a href="{{ $this->editUrlFor($lunch) }}"
                                style="display:block;text-decoration:none;padding:0.375rem 0.5rem;background:var(--primary-50,#eef2ff);border:1px solid var(--primary-200,#c7d2fe);border-radius:0.375rem;margin-top:0.25rem;">
                                <div style="font-size:0.75rem;font-weight:600;color:var(--primary-700,#4338ca);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:0.25rem;">
                                    {{ $lunch->catererMeal?->name ?? 'Menu' }}
                                </div>
                                <div style="display:flex;flex-wrap:wrap;gap:0.25rem;">
                                    @foreach($lunch->options->where('enabled', true) as $option)
                                        @if($option->mealOption)
                                            <x-filament::badge color="primary" size="sm">
                                                {{ $option->mealOption->name }}
                                            </x-filament::badge>
                                        @endif
                                    @endforeach
                                </div>
                            </a>
                        @elseif($inMonth && !$isWeekend)
                            {{-- Empty weekday: show set menu link --}}
                            <a href="{{ $this->createUrlFor($day['date']) }}"
                                style="display:block;text-decoration:none;margin-top:0.25rem;padding:0.25rem 0;font-size:0.75rem;color:var(--gray-400,#9ca3af);text-align:center;">
                                + Set menu
                            </a>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
