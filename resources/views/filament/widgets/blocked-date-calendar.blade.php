<x-filament-widgets::widget>
    @php
        $storeOptions = $this->getStoreOptions();
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;
        $days = $this->getCalendarDays();
        $dayLabels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        $monthLabel = \Illuminate\Support\Carbon::parse($month . '-01')->translatedFormat('F Y');
    @endphp

    <x-filament::section>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="prevMonth" class="flex h-7 w-7 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 dark:border-white/10 dark:text-gray-400 dark:hover:bg-white/5">
                    <x-heroicon-o-chevron-left class="h-3.5 w-3.5" />
                </button>
                <span class="min-w-[8rem] text-center text-sm font-semibold">{{ $monthLabel }}</span>
                <button type="button" wire:click="nextMonth" class="flex h-7 w-7 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 dark:border-white/10 dark:text-gray-400 dark:hover:bg-white/5">
                    <x-heroicon-o-chevron-right class="h-3.5 w-3.5" />
                </button>
                <button type="button" wire:click="goToday" class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                    Hari Ini
                </button>
            </div>

            @if ($isFullAccess && count($storeOptions) > 1)
                <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-2.5 py-1 text-xs dark:border-white/10">
                    <x-heroicon-o-building-storefront class="h-3.5 w-3.5 text-gray-500 dark:text-gray-400" />
                    <select wire:model.live="storeId" class="border-0 bg-transparent p-0 pr-6 text-xs font-medium focus:ring-0 dark:[color-scheme:dark]">
                        @foreach ($storeOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>

        <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            @foreach ($dayLabels as $i => $label)
                <div @class(['pb-1', 'text-danger-400 dark:text-danger-400/70' => $i >= 5])>{{ $label }}</div>
            @endforeach
        </div>

        <div class="grid grid-cols-7 gap-1">
            @foreach ($days as $i => $day)
                @php
                    $dayNum = \Illuminate\Support\Carbon::parse($day['date'])->day;
                    $isWeekend = ($i % 7) >= 5;
                @endphp
                <div
                    title="{{ $day['blocked'] && $day['reason'] ? $day['reason'] : '' }}"
                    @class([
                        'flex min-h-[52px] flex-col items-start gap-1 rounded-lg border p-1.5',
                        'border-gray-200 dark:border-white/10' => ! $day['blocked'],
                        'bg-gray-50/60 dark:bg-white/[0.02]' => $isWeekend && ! $day['blocked'] && $day['inMonth'],
                        'border-danger-300 bg-danger-50/70 dark:border-danger-500/40 dark:bg-danger-500/10' => $day['blocked'],
                        'ring-1 ring-primary-400' => $day['isToday'],
                        'opacity-40' => ! $day['inMonth'],
                    ])
                >
                    <span @class([
                        'text-[11px] font-semibold',
                        'text-danger-600 dark:text-danger-400' => $day['blocked'],
                        'text-gray-700 dark:text-gray-200' => ! $day['blocked'],
                    ])>
                        {{ $dayNum }}
                    </span>

                    @if ($day['blocked'])
                        <span class="line-clamp-2 text-[9px] leading-tight text-danger-500 dark:text-danger-400/80">
                            {{ $day['reason'] ?: 'Tutup' }}
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
