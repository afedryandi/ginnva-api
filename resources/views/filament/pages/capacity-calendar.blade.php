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
        {{-- Legenda pakai swatch warna sungguhan (bukan cuma teks) —
             dirapikan 2026-09-25 (laporan user, "layout & UI kalender
             jelek") supaya langsung kebaca sekilas tanpa perlu baca
             kalimat panjang. --}}
        <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-gray-500 dark:text-gray-400">
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-primary-500"></span> Hari ini</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full border-2 border-primary-500"></span> Sudah di-override</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-danger-500"></span> Kapasitas penuh</span>
            <span class="ml-auto text-gray-400 dark:text-gray-500">Klik tanggal (hari ini/ke depan) untuk atur kapasitas.</span>
        </div>

        <div class="grid grid-cols-7 gap-1.5 text-center text-[11px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            @foreach ($dayLabels as $i => $label)
                <div @class(['pb-2', 'text-danger-400 dark:text-danger-400/70' => $i >= 5])>{{ $label }}</div>
            @endforeach
        </div>

        <div class="grid grid-cols-7 gap-1.5">
            @foreach ($days as $i => $day)
                @php
                    $dayNum = \Illuminate\Support\Carbon::parse($day['date'])->day;
                    $isFull = ! $day['closed'] && $day['used'] >= $day['capacity'];
                    $isWeekend = ($i % 7) >= 5;
                    $clickable = $day['inMonth'] && ! $day['isPast'] && ! $day['closed'];
                    $fillPct = (! $day['closed'] && $day['capacity'] > 0) ? min(100, round($day['used'] / $day['capacity'] * 100)) : 0;
                @endphp
                <button
                    type="button"
                    @if ($clickable) wire:click="openDay('{{ $day['date'] }}')" @else disabled @endif
                    @class([
                        'relative flex min-h-[80px] flex-col items-start gap-1.5 rounded-xl border p-2.5 text-left transition',
                        'border-gray-200 dark:border-white/10' => ! $isFull && ! $day['isToday'],
                        'bg-gray-50/60 dark:bg-white/[0.02]' => $isWeekend && ! $isFull && ! $day['isToday'] && $day['inMonth'],
                        'border-primary-300 bg-primary-50/70 dark:border-primary-500/40 dark:bg-primary-500/10' => $day['isToday'] && ! $isFull,
                        'border-danger-300 bg-danger-50/70 dark:border-danger-500/40 dark:bg-danger-500/10' => $isFull,
                        'opacity-40' => ! $day['inMonth'] || $day['isPast'],
                        'cursor-pointer hover:border-primary-300 hover:shadow-sm dark:hover:border-primary-500/50' => $clickable,
                        'cursor-default' => ! $clickable,
                    ])
                >
                    <div class="flex w-full items-center justify-between">
                        <span @class([
                            'flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold',
                            'bg-primary-600 text-white' => $day['isToday'],
                            'text-gray-700 dark:text-gray-200' => ! $day['isToday'],
                        ])>
                            {{ $dayNum }}
                        </span>

                        @if ($day['hasOverride'])
                            <span title="Sudah di-override manual" class="h-2 w-2 flex-shrink-0 rounded-full border-2 border-primary-500 dark:border-primary-400"></span>
                        @endif
                    </div>

                    @if ($day['closed'])
                        <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500">Libur</span>
                    @else
                        <div class="mt-auto w-full">
                            <span @class([
                                'text-sm font-bold tabular-nums',
                                'text-danger-600 dark:text-danger-400' => $isFull,
                                'text-gray-700 dark:text-gray-200' => ! $isFull,
                            ])>
                                {{ $day['used'] }}<span class="text-gray-400 dark:text-gray-500">/{{ $day['capacity'] }}</span>
                            </span>
                            <div class="mt-1 h-1 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                <div
                                    class="h-full rounded-full {{ $isFull ? 'bg-danger-500' : 'bg-primary-400' }}"
                                    style="width: {{ $fillPct }}%"
                                ></div>
                            </div>
                        </div>
                    @endif
                </button>
            @endforeach
        </div>
    </x-filament::section>

    {{--
        Panel edit — BUG DIPERBAIKI 2026-09-25 (laporan user, dua ronde):
        Ronde 1 ("pop up rusak desainnya") — modal manual pakai class
        Tailwind eksotis (fixed, inset-0, z-50, bg-black/40, shadow-xl,
        max-w-sm) yang TIDAK PERNAH ter-compile ke CSS Filament (proyek
        ini tidak punya theme kustom, panel pakai CSS precompiled
        vendor). Percobaan pertama diganti <x-filament::modal> resmi.
        Ronde 2 ("tanggal tidak bisa diklik") — TERNYATA prop `visible`
        di <x-filament::modal> cuma "initial visibility", TIDAK reaktif
        tiap Livewire re-render (Alpine state-nya dipertahankan morph,
        bukan dievaluasi ulang dari Blade) — backdrop modal nyangkut
        menutupi SELURUH halaman & memblokir klik kalender walau
        $editingDate sudah null lagi.
        FIX FINAL: kembali ke @if($editingDate)/div manual (dijamin
        BENAR-BENAR tidak ada di DOM saat tertutup, mustahil memblokir
        klik apa pun), styling pakai CSS POLOS di <style> di bawah
        (bukan class Tailwind) -- CSS polos SELALU berlaku di browser
        apa pun isi compiled Tailwind bundle-nya, tidak bergantung pada
        proses build/purge apa pun.
    --}}
    <style>
        .capacity-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(0, 0, 0, 0.4);
            padding: 1rem;
        }
        .capacity-modal-card {
            width: 100%;
            max-width: 24rem;
            border-radius: 0.75rem;
            background-color: #ffffff;
            padding: 1.25rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        :root.dark .capacity-modal-card {
            background-color: #111827;
        }
    </style>

    @if ($editingDate)
        <div class="capacity-modal-backdrop" x-data x-on:keydown.escape.window="$wire.closeEdit()">
            <div class="capacity-modal-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">
                    Kapasitas {{ \Illuminate\Support\Carbon::parse($editingDate)->translatedFormat('d F Y (l)') }}
                </h3>

                @php
                    $editingUsed = collect($days)->firstWhere('date', $editingDate)['used'] ?? 0;
                @endphp
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $editingUsed }} booking confirmed sudah menempati tanggal ini.
                </p>

                {{-- Gap "siapa & kapan" (audit Kalender Kapasitas 2026-09-25)
                     — sebelumnya staff harus gali activity_log manual
                     untuk tahu siapa yang terakhir mengubah kapasitas
                     tanggal ini. --}}
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

    {{-- Panel "Atur Rentang Tanggal" (gap bulk-edit, audit Kalender
         Kapasitas 2026-09-25) — pola & styling sama persis panel edit
         di atas (div manual + CSS polos). --}}
    @if ($rangeEditorOpen)
        <div class="capacity-modal-backdrop" x-data x-on:keydown.escape.window="$wire.closeRangeEditor()">
            <div class="capacity-modal-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Atur Kapasitas Rentang Tanggal</h3>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
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

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        wire:click="closeRangeEditor"
                        class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-300"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        wire:click="applyRangeCapacity"
                        class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-500"
                    >
                        Terapkan
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
