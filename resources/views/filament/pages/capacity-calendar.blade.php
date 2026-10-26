<x-filament-panels::page>
    @php
        $storeOptions = $this->getStoreOptions();
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;
        $days = $this->getCalendarDays();
        $dayLabels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        $monthLabel = \Illuminate\Support\Carbon::parse($month . '-01')->translatedFormat('F Y');
        $canCapacity = \App\Filament\Pages\CapacityCalendar::canManageCapacity();
        $canClose = \App\Filament\Pages\CapacityCalendar::canManageClosures('create');
        $canReopen = \App\Filament\Pages\CapacityCalendar::canManageClosures('delete');
        $canEditReason = \App\Filament\Pages\CapacityCalendar::canManageClosures('update');
        $closures = $this->getUpcomingClosures();
        $editing = $editingDate ? collect($days)->firstWhere('date', $editingDate) : null;
        $btn = 'rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5';
        $input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white';
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
            <button type="button" wire:click="goToday" class="{{ $btn }}">Hari Ini</button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($canCapacity)
                <button type="button" wire:click="openRangeEditor" class="{{ $btn }}">Atur Rentang Tanggal</button>
                <button
                    type="button"
                    wire:click="clearAllOverrides"
                    wire:confirm="Hapus SEMUA override kapasitas toko ini (hari ini & seterusnya)? Semua tanggal kembali pakai kapasitas default toko."
                    class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-danger-50 hover:text-danger-600 dark:border-white/10 dark:text-gray-300 dark:hover:bg-danger-500/10 dark:hover:text-danger-400"
                >
                    Hapus Semua Override
                </button>
            @endif
            @if ($canClose)
                <button type="button" wire:click="openClosureRange" class="{{ $btn }}">Tutup Rentang Tanggal</button>
                @if ($isFullAccess)
                    <button type="button" wire:click="openCloseAll" class="{{ $btn }}">Tutup Semua Toko</button>
                @endif
            @endif

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
        {{-- Legenda pakai swatch warna sungguhan supaya langsung terbaca sekilas. --}}
        <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-gray-500 dark:text-gray-400">
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-primary-500"></span> Hari ini</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full border-2 border-primary-500"></span> Sudah di-override</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-danger-500"></span> Kapasitas penuh</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-gray-400"></span> Ditutup (tanggal tutup)</span>
            <span class="text-gray-400 dark:text-gray-500">Angka = kapasitas harian layanan berat (PPF/kaca film). Detailing &amp; Premium Wash dihitung per jam, diatur di data Toko. Libur rutin mingguan diatur di Toko &gt; Jam Operasional.</span>
            <span class="ml-auto text-gray-400 dark:text-gray-500">Klik tanggal (hari ini/ke depan) untuk atur kapasitas atau menutup/membukanya.</span>
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
                    $clickable = $day['inMonth'] && ! $day['isPast'] && ! $day['weeklyClosed'];
                    $fillPct = (! $day['closed'] && $day['capacity'] > 0) ? min(100, round($day['used'] / $day['capacity'] * 100)) : 0;
                @endphp
                <button
                    type="button"
                    @if ($clickable) wire:click="openDay('{{ $day['date'] }}')" @else disabled @endif
                    @if ($day['blocked'] && $day['blockedReason']) title="{{ $day['blockedReason'] }}" @endif
                    @class([
                        'relative flex min-h-[80px] flex-col items-start gap-1.5 rounded-xl border p-2.5 text-left transition',
                        'border-gray-200 dark:border-white/10' => ! $isFull && ! $day['isToday'] && ! $day['blocked'],
                        'bg-gray-50/60 dark:bg-white/[0.02]' => $isWeekend && ! $isFull && ! $day['isToday'] && $day['inMonth'] && ! $day['blocked'],
                        'border-primary-300 bg-primary-50/70 dark:border-primary-500/40 dark:bg-primary-500/10' => $day['isToday'] && ! $isFull && ! $day['blocked'],
                        'border-danger-300 bg-danger-50/70 dark:border-danger-500/40 dark:bg-danger-500/10' => $isFull,
                        'border-gray-300 bg-gray-100 dark:border-white/20 dark:bg-white/10' => $day['blocked'],
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

                    @if ($day['blocked'])
                        <span class="text-[11px] font-semibold text-gray-600 dark:text-gray-300">Ditutup</span>
                        @if ($day['blockedReason'])
                            <span class="w-full truncate text-[10px] text-gray-500 dark:text-gray-400">{{ $day['blockedReason'] }}</span>
                        @endif
                    @elseif ($day['weeklyClosed'])
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

    {{-- Daftar tanggal tutup yang akan datang untuk toko ini (pengganti tabel menu Tanggal Tidak Tersedia). --}}
    <x-filament::section>
        <x-slot name="heading">Tanggal ditutup yang akan datang</x-slot>
        <x-slot name="description">Pelanggan tidak bisa memilih tanggal ini di aplikasi, dan tanggal ini tidak dihitung Alpha maupun jam kerjanya di gaji. Booking yang sudah ada tidak dibatalkan.</x-slot>

        @if ($closures->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada tanggal yang ditutup.</p>
        @else
            <div class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                @foreach ($closures as $closure)
                    <div class="flex items-center justify-between gap-3 py-2">
                        <div>
                            <span class="font-medium">{{ $closure->date->translatedFormat('l, d M Y') }}</span>
                            <span class="ms-2 text-gray-500 dark:text-gray-400">{{ $closure->reason ?: '—' }}</span>
                        </div>
                        @if ($canReopen)
                            <button
                                type="button"
                                wire:click="reopenById({{ $closure->id }})"
                                wire:confirm="Buka kembali tanggal ini untuk booking?"
                                class="{{ $btn }}"
                            >
                                Buka Kembali
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{--
        Panel di bawah memakai div manual dengan CSS polos (bukan <x-filament::modal> dan bukan class Tailwind eksotis): prop `visible` modal
        Filament tidak reaktif antar render Livewire dan backdropnya pernah menutupi seluruh halaman, sedangkan class Tailwind tidak ikut
        ter-compile ke CSS panel. Dengan @if, panel dijamin tidak ada di DOM saat tertutup.
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
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 0.75rem;
            background-color: #ffffff;
            padding: 1.25rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        :root.dark .capacity-modal-card {
            background-color: #111827;
        }
    </style>

    @if ($editingDate && $editing)
        <div class="capacity-modal-backdrop" x-data x-on:keydown.escape.window="$wire.closeEdit()">
            <div class="capacity-modal-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">
                    {{ \Illuminate\Support\Carbon::parse($editingDate)->translatedFormat('d F Y (l)') }}
                </h3>

                @if ($editingBlocked)
                    {{-- Tanggal sedang ditutup: kapasitas tidak berlaku, yang bisa dilakukan hanya ubah alasan atau buka kembali. --}}
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Tanggal ini ditutup. Pelanggan tidak bisa memilihnya, dan kapasitas tidak berlaku selama tanggal ditutup.
                    </p>

                    <label class="mt-4 block text-xs font-medium text-gray-600 dark:text-gray-300">Alasan</label>
                    <input type="text" maxlength="255" wire:model="editingReason" @disabled(! $canEditReason) class="{{ $input }}" />

                    <div class="mt-5 flex items-center justify-between gap-2">
                        @if ($canReopen)
                            <button
                                type="button"
                                wire:click="reopenDay"
                                wire:confirm="Buka kembali tanggal ini untuk booking?"
                                class="text-xs font-medium text-gray-500 hover:text-danger-600 dark:text-gray-400 dark:hover:text-danger-400"
                            >
                                Buka Kembali
                            </button>
                        @else
                            <span></span>
                        @endif
                        <div class="flex gap-2">
                            <button type="button" wire:click="closeEdit" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-300">Tutup Panel</button>
                            @if ($canEditReason)
                                <button type="button" wire:click="saveBlockedReason" class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-500">Simpan Alasan</button>
                            @endif
                        </div>
                    </div>
                @else
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ $editing['used'] }} booking confirmed sudah menempati tanggal ini.
                    </p>

                    @if ($canCapacity)
                        @if ($editingOverrideInfo)
                            <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">
                                Diubah oleh {{ $editingOverrideInfo['name'] }} pada {{ $editingOverrideInfo['at'] }}.
                            </p>
                        @else
                            <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">Belum pernah di-override — masih pakai default toko.</p>
                        @endif

                        <label class="mt-4 block text-xs font-medium text-gray-600 dark:text-gray-300">Kapasitas</label>
                        <input type="number" min="1" wire:model="editingCapacity" class="{{ $input }}" />

                        <div class="mt-4 flex items-center justify-between gap-2">
                            <button
                                type="button"
                                wire:click="clearOverride"
                                class="text-xs font-medium text-gray-500 hover:text-danger-600 dark:text-gray-400 dark:hover:text-danger-400"
                            >
                                Hapus override (pakai default)
                            </button>
                            <button type="button" wire:click="saveCapacity" class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-500">Simpan Kapasitas</button>
                        </div>
                    @endif

                    @if ($canClose)
                        <div class="mt-5 border-t border-gray-200 pt-4 dark:border-white/10">
                            <div class="text-xs font-semibold text-gray-700 dark:text-gray-200">Tutup tanggal ini</div>
                            <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                                Menutup penuh (libur, renovasi, acara). Booking yang sudah ada tidak dibatalkan.
                            </p>
                            <input
                                type="text"
                                maxlength="255"
                                wire:model="editingReason"
                                placeholder="Alasan (opsional), mis. Libur nasional"
                                class="{{ $input }}"
                            />
                            <div class="mt-3 flex justify-end">
                                <button type="button" wire:click="closeDay" class="rounded-lg border border-danger-300 px-3 py-1.5 text-xs font-semibold text-danger-600 hover:bg-danger-50 dark:border-danger-500/40 dark:text-danger-400 dark:hover:bg-danger-500/10">Tutup Tanggal</button>
                            </div>
                        </div>
                    @endif

                    <div class="mt-4 flex justify-end">
                        <button type="button" wire:click="closeEdit" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-300">Batal</button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Panel "Atur Rentang Tanggal" (kapasitas untuk banyak tanggal sekaligus). --}}
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
                        <input type="date" wire:model="rangeFrom" class="{{ $input }} dark:[color-scheme:dark]" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Sampai Tanggal</label>
                        <input type="date" wire:model="rangeTo" class="{{ $input }} dark:[color-scheme:dark]" />
                    </div>
                </div>

                <label class="mt-3 block text-xs font-medium text-gray-600 dark:text-gray-300">Kapasitas</label>
                <input type="number" min="1" wire:model="rangeCapacity" class="{{ $input }}" />

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" wire:click="closeRangeEditor" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-300">Batal</button>
                    <button type="button" wire:click="applyRangeCapacity" class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-500">Terapkan</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Panel "Tutup Rentang Tanggal": satu toko, beberapa hari sekaligus. --}}
    @if ($closureRangeOpen)
        <div class="capacity-modal-backdrop" x-data x-on:keydown.escape.window="$wire.closeClosureRange()">
            <div class="capacity-modal-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Tutup Rentang Tanggal</h3>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Menutup semua tanggal dalam rentang untuk toko ini. Tanggal yang sudah ditutup dilewati, tanggal lampau tidak bisa ditutup. Untuk libur rutin tiap minggu (mis. selalu libur hari Minggu), atur di Toko &gt; Jam Operasional.
                </p>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Dari Tanggal</label>
                        <input type="date" wire:model.live="closureFrom" min="{{ now()->toDateString() }}" class="{{ $input }} dark:[color-scheme:dark]" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Sampai Tanggal</label>
                        <input type="date" wire:model.live="closureTo" min="{{ $closureFrom ?: now()->toDateString() }}" class="{{ $input }} dark:[color-scheme:dark]" />
                    </div>
                </div>

                <label class="mt-3 block text-xs font-medium text-gray-600 dark:text-gray-300">Alasan (opsional)</label>
                <input type="text" maxlength="255" wire:model="closureReason" placeholder="Contoh: Libur nasional, Workshop penuh, Maintenance" class="{{ $input }}" />

                @php $overlap = $this->closureOverlapLines(); @endphp
                @if ($overlap)
                    <div class="mt-3 text-xs text-warning-600 dark:text-warning-400">
                        @foreach ($overlap as $line)
                            <div>⚠ {{ $line }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" wire:click="closeClosureRange" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-300">Batal</button>
                    <button type="button" wire:click="applyClosureRange" class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-500">Tutup Tanggal</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Panel "Tutup Semua Toko": libur nasional / cuti bersama untuk seluruh toko aktif. Hanya akses penuh. --}}
    @if ($closeAllOpen)
        <div class="capacity-modal-backdrop" x-data x-on:keydown.escape.window="$wire.closeCloseAll()">
            <div class="capacity-modal-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Tutup Semua Toko</h3>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Menutup semua toko aktif pada tanggal ini, misalnya libur nasional atau cuti bersama. Tanggal ini tidak bisa dibooking, tidak dihitung Alpha, dan tidak dihitung jam kerjanya di gaji. Booking yang sudah ada tidak dibatalkan.
                </p>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Dari Tanggal</label>
                        <input type="date" wire:model="allFrom" min="{{ now()->toDateString() }}" class="{{ $input }} dark:[color-scheme:dark]" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Sampai Tanggal</label>
                        <input type="date" wire:model="allTo" class="{{ $input }} dark:[color-scheme:dark]" />
                    </div>
                </div>

                <label class="mt-3 block text-xs font-medium text-gray-600 dark:text-gray-300">Alasan</label>
                <input type="text" maxlength="255" wire:model="allReason" placeholder="Mis. Libur Nasional - Hari Kemerdekaan" class="{{ $input }}" />

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" wire:click="closeCloseAll" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-300">Batal</button>
                    <button type="button" wire:click="applyCloseAll" class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-500">Tutup Semua Toko</button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
