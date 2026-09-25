<x-filament-panels::page>
    @php
        $storeOptions = $this->getStoreOptions();
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;
        $days = $this->getCalendarDays();
        $dayLabels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        $monthLabel = \Illuminate\Support\Carbon::parse($month . '-01')->translatedFormat('F Y');
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <button
                type="button"
                wire:click="prevMonth"
                class="flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 dark:border-white/10 dark:text-gray-400 dark:hover:bg-white/5"
            >
                <x-heroicon-o-chevron-left class="h-4 w-4" />
            </button>
            <span class="min-w-[9rem] text-center text-sm font-semibold">{{ $monthLabel }}</span>
            <button
                type="button"
                wire:click="nextMonth"
                class="flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 dark:border-white/10 dark:text-gray-400 dark:hover:bg-white/5"
            >
                <x-heroicon-o-chevron-right class="h-4 w-4" />
            </button>
            <button
                type="button"
                wire:click="goToday"
                class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5"
            >
                Hari Ini
            </button>
        </div>

        @if ($isFullAccess && count($storeOptions) > 1)
            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm dark:border-white/10">
                <x-heroicon-o-building-storefront class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                <select wire:model.live="storeId" class="border-0 bg-transparent p-0 pr-7 text-sm font-medium focus:ring-0 dark:[color-scheme:dark]">
                    @foreach ($storeOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>

    <x-filament::section>
        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
            Klik tanggal (hari ini atau ke depan) untuk mengatur kapasitas instalasi hari itu. Tanpa override, kapasitas ikut default toko. Badge <span class="font-semibold text-primary-600 dark:text-primary-400">oranye</span> menandai tanggal yang sudah di-override manual.
        </p>

        <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-semibold uppercase text-gray-400 dark:text-gray-500">
            @foreach ($dayLabels as $label)
                <div class="py-1">{{ $label }}</div>
            @endforeach
        </div>

        <div class="grid grid-cols-7 gap-1">
            @foreach ($days as $day)
                @php
                    $dayNum = \Illuminate\Support\Carbon::parse($day['date'])->day;
                    $isFull = ! $day['closed'] && $day['used'] >= $day['capacity'];
                    $clickable = $day['inMonth'] && ! $day['isPast'] && ! $day['closed'];
                @endphp
                <button
                    type="button"
                    @if ($clickable) wire:click="openDay('{{ $day['date'] }}')" @else disabled @endif
                    @class([
                        'flex min-h-[64px] flex-col items-start rounded-lg border p-1.5 text-left transition',
                        'border-gray-200 dark:border-white/10' => ! $isFull,
                        'border-danger-300 dark:border-danger-500/40' => $isFull,
                        'opacity-40' => ! $day['inMonth'] || $day['isPast'],
                        'ring-2 ring-primary-500' => $day['isToday'],
                        'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5' => $clickable,
                        'cursor-default' => ! $clickable,
                    ])
                >
                    <span class="text-xs font-semibold {{ $day['isToday'] ? 'text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-200' }}">
                        {{ $dayNum }}
                    </span>

                    @if ($day['closed'])
                        <span class="mt-1 text-[10px] text-gray-400 dark:text-gray-500">Libur</span>
                    @else
                        <span class="mt-1 text-[11px] font-bold tabular-nums {{ $isFull ? 'text-danger-600 dark:text-danger-400' : 'text-gray-600 dark:text-gray-300' }}">
                            {{ $day['used'] }}/{{ $day['capacity'] }}
                        </span>
                        @if ($day['hasOverride'])
                            <span class="mt-0.5 rounded-full bg-primary-100 px-1.5 py-0.5 text-[9px] font-semibold text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">
                                Override
                            </span>
                        @endif
                    @endif
                </button>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Panel edit — plain Livewire property (bukan Filament Actions),
         pola sama dengan SalesDashboard.php: wire:click ke method PHP
         biasa, ->live() lewat property publik. --}}
    @if ($editingDate)
        <div
            x-data
            x-on:keydown.escape.window="$wire.closeEdit()"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        >
            <div class="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">
                    Kapasitas {{ \Illuminate\Support\Carbon::parse($editingDate)->translatedFormat('d F Y (l)') }}
                </h3>

                @php
                    $editingUsed = collect($days)->firstWhere('date', $editingDate)['used'] ?? 0;
                @endphp
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $editingUsed }} booking confirmed sudah menempati tanggal ini.
                </p>

                <label class="mt-4 block text-xs font-medium text-gray-600 dark:text-gray-300">Kapasitas</label>
                <input
                    type="number"
                    min="1"
                    wire:model="editingCapacity"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white"
                />

                <div class="mt-5 flex items-center justify-between gap-2">
                    <button
                        type="button"
                        wire:click="clearOverride"
                        class="text-xs font-medium text-gray-500 hover:text-danger-600 dark:text-gray-400 dark:hover:text-danger-400"
                    >
                        Hapus override (pakai default)
                    </button>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            wire:click="closeEdit"
                            class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-300"
                        >
                            Batal
                        </button>
                        <button
                            type="button"
                            wire:click="saveCapacity"
                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-500"
                        >
                            Simpan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
