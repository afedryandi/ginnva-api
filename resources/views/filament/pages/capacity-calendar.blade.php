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

        <div class="flex items-center gap-2">
            {{-- Gap "bulk edit rentang tanggal" & "bulk-clear" (audit
                 Kalender Kapasitas 2026-09-25) — sebelumnya staff wajib
                 klik satu-per-satu untuk skenario umum ("minggu depan
                 kapasitas turun jadi 2"). --}}
            <button
                type="button"
                wire:click="openRangeEditor"
                class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5"
            >
                Atur Rentang Tanggal
            </button>
            <button
                type="button"
                wire:click="clearAllOverrides"
                wire:confirm="Hapus SEMUA override kapasitas toko ini (hari ini & seterusnya)? Semua tanggal kembali pakai kapasitas default toko."
                class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-danger-50 hover:text-danger-600 dark:border-white/10 dark:text-gray-300 dark:hover:bg-danger-500/10 dark:hover:text-danger-400"
            >
                Hapus Semua Override
            </button>

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

    {{--
        Panel edit — BUG DIPERBAIKI 2026-09-25 (laporan user, "pop up
        rusak desainnya"): SEBELUMNYA modal ini dibangun manual dengan
        div `fixed inset-0 z-50 ...` + Tailwind classes custom (max-w-sm,
        shadow-xl, bg-black/40, dst). Root cause: proyek ini TIDAK punya
        Filament theme kustom (tidak ada resources/css/filament/**,
        panel pakai CSS bawaan Filament yang di-precompile vendor,
        BUKAN di-build ulang dari tailwind.config.js proyek ini) — jadi
        class Tailwind yang cuma dipakai di file INI (tidak pernah
        dipakai file Filament lain mana pun, dikonfirmasi grep) tidak
        pernah ter-compile ke CSS yang benar-benar dimuat browser,
        modal tampil tanpa background/border/posisi sama sekali.
        Diganti pakai <x-filament::modal> resmi -- komponen inti
        Filament yang sudah pasti ter-compile (dipakai di seluruh
        panel admin), bukan lagi Tailwind mentah buatan sendiri.
        closeByClickingAway/closeByEscaping DIMATIKAN sengaja -- state
        "terbuka" murni dikontrol Livewire ($editingDate), bukan Alpine
        lokal, supaya tidak ada jalur tutup yang lupa reset properti PHP.
    --}}
    <x-filament::modal id="capacity-edit-modal" :visible="$editingDate !== null" width="sm" :close-by-clicking-away="false" :close-by-escaping="false" :close-button="false">
        <x-slot name="heading">
            @if ($editingDate)
                Kapasitas {{ \Illuminate\Support\Carbon::parse($editingDate)->translatedFormat('d F Y (l)') }}
            @endif
        </x-slot>

        @if ($editingDate)
            @php
                $editingUsed = collect($days)->firstWhere('date', $editingDate)['used'] ?? 0;
            @endphp
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ $editingUsed }} booking confirmed sudah menempati tanggal ini.
            </p>

            {{-- Gap "siapa & kapan" (audit Kalender Kapasitas 2026-09-25)
                 — sebelumnya staff harus gali activity_log manual untuk
                 tahu siapa yang terakhir mengubah kapasitas tanggal ini. --}}
            @if ($editingOverrideInfo)
                <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">
                    Diubah oleh {{ $editingOverrideInfo['name'] }} pada {{ $editingOverrideInfo['at'] }}.
                </p>
            @else
                <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">Belum pernah di-override — masih pakai default toko.</p>
            @endif

            <label class="mt-4 block text-xs font-medium text-gray-600 dark:text-gray-300">Kapasitas</label>
            <input
                type="number"
                min="1"
                wire:model="editingCapacity"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white"
            />
        @endif

        <x-slot name="footerActions">
            @if ($editingDate)
                <button
                    type="button"
                    wire:click="clearOverride"
                    class="text-xs font-medium text-gray-500 hover:text-danger-600 dark:text-gray-400 dark:hover:text-danger-400"
                >
                    Hapus override (pakai default)
                </button>
                <x-filament::button wire:click="closeEdit" color="gray" size="sm">Batal</x-filament::button>
                <x-filament::button wire:click="saveCapacity" size="sm">Simpan</x-filament::button>
            @endif
        </x-slot>
    </x-filament::modal>

    {{-- Panel "Atur Rentang Tanggal" (gap bulk-edit, audit Kalender
         Kapasitas 2026-09-25) — pola sama persis (<x-filament::modal>). --}}
    <x-filament::modal id="capacity-range-modal" :visible="$rangeEditorOpen" width="sm" :close-by-clicking-away="false" :close-by-escaping="false" :close-button="false">
        <x-slot name="heading">Atur Kapasitas Rentang Tanggal</x-slot>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Terapkan 1 angka kapasitas ke semua tanggal dalam rentang ini sekaligus (mis. "minggu depan kapasitas turun jadi 2 karena kurang installer"). Tanggal lampau dalam rentang dilewati otomatis.
        </p>

        <div class="mt-4 grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Dari Tanggal</label>
                <input
                    type="date"
                    wire:model="rangeFrom"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white dark:[color-scheme:dark]"
                />
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Sampai Tanggal</label>
                <input
                    type="date"
                    wire:model="rangeTo"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white dark:[color-scheme:dark]"
                />
            </div>
        </div>

        <label class="mt-3 block text-xs font-medium text-gray-600 dark:text-gray-300">Kapasitas</label>
        <input
            type="number"
            min="1"
            wire:model="rangeCapacity"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white"
        />

        <x-slot name="footerActions">
            <x-filament::button wire:click="closeRangeEditor" color="gray" size="sm">Batal</x-filament::button>
            <x-filament::button wire:click="applyRangeCapacity" size="sm">Terapkan</x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
