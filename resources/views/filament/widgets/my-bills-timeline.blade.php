<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">My Bills</x-slot>

        <div class="flex flex-wrap gap-8 mb-6">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Outstanding</div>
                <div @class([
                    'text-2xl font-bold',
                    'text-danger-600 dark:text-danger-400' => $this->getOutstanding() > 0,
                    'text-success-600 dark:text-success-400' => $this->getOutstanding() <= 0,
                ])>
                    MYR {{ number_format($this->getOutstanding(), 2) }}
                </div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Available credit</div>
                <div class="text-2xl font-bold text-gray-950 dark:text-white">
                    MYR {{ number_format($this->getCredit(), 2) }}
                </div>
            </div>
        </div>

        @php
            $bills = $this->getBills();
        @endphp

        @if ($bills->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No bills yet.</p>
        @else
            <ol class="relative ms-2 border-s border-gray-200 dark:border-gray-700">
                @foreach ($bills as $bill)
                    @php
                        $paid = (float) $bill->allocations_sum_amount_allocated;
                        $due = (float) $bill->amount_due;
                        $remaining = max(0, $due - $paid);
                        [$badgeLabel, $badgeColor] = match ($bill->status) {
                            'paid' => $bill->type === 'free' ? ['Free', 'gray'] : ['Paid', 'success'],
                            'partial' => ['Partial', 'warning'],
                            default => ['Unpaid', 'danger'],
                        };
                    @endphp
                    <li class="mb-6 ms-6">
                        <span @class([
                            'absolute -start-1.5 mt-1.5 flex h-3 w-3 rounded-full ring-4 ring-white dark:ring-gray-900',
                            'bg-success-500' => $badgeColor === 'success',
                            'bg-warning-500' => $badgeColor === 'warning',
                            'bg-danger-500' => $badgeColor === 'danger',
                            'bg-gray-400' => $badgeColor === 'gray',
                        ])></span>
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <h3 class="font-semibold text-gray-950 dark:text-white">
                                    {{ $bill->month_date->format('F Y') }}
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    MYR {{ number_format($due, 2) }}
                                    @if ($remaining > 0)
                                        · <span class="text-danger-600 dark:text-danger-400">MYR {{ number_format($remaining, 2) }} remaining</span>
                                    @elseif ($paid > 0)
                                        · MYR {{ number_format($paid, 2) }} paid
                                    @endif
                                </p>
                            </div>
                            <x-filament::badge :color="$badgeColor">{{ $badgeLabel }}</x-filament::badge>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
