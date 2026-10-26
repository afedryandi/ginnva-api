<x-filament-panels::page>
    @php
        $cc = \App\Filament\Pages\CapacityCalendar::class;
        $storeOptions = $this->getStoreOptions();
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;
        $days = $this->getCalendarDays();
        $closures = $this->getUpcomingClosures();
        $dayLabels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        $canReopen = $cc::canManageClosures('delete');
        $canOpenDay = $cc::canManageCapacity() || $cc::canManageClosures('create') || $cc::canManageClosures('update') || $canReopen;
    @endphp

    {{-- Gaya khusus kotak kalender: CSS polos berprefiks .cc- (panel memakai CSS Filament yang sudah dikompilasi, class Tailwind proyek tidak tersedia). --}}
    <style>
        .cc {
            --cc-ink: #111827; --cc-text: #1f2937; --cc-muted: #6b7280; --cc-line: #e5e7eb;
            --cc-surface: #ffffff; --cc-soft: #f9fafb; --cc-hover: #f3f4f6;
            --cc-danger: #dc2626; --cc-danger-soft: #fef2f2; --cc-danger-line: #fecaca;
            --cc-warn: #d97706; --cc-closed: #e5e7eb; --cc-bar: #9ca3af; --cc-bar-bg: #e5e7eb;
            --cc-today-bg: #111827; --cc-today-fg: #ffffff;
        }
        .dark .cc {
            --cc-ink: #f9fafb; --cc-text: #e5e7eb; --cc-muted: #9ca3af; --cc-line: rgba(255, 255, 255, .12);
            --cc-surface: transparent; --cc-soft: rgba(255, 255, 255, .04); --cc-hover: rgba(255, 255, 255, .08);
            --cc-danger: #f87171; --cc-danger-soft: rgba(248, 113, 113, .12); --cc-danger-line: rgba(248, 113, 113, .35);
            --cc-warn: #fbbf24; --cc-closed: rgba(255, 255, 255, .1); --cc-bar: #9ca3af; --cc-bar-bg: rgba(255, 255, 255, .14);
            --cc-today-bg: #f9fafb; --cc-today-fg: #111827;
        }
        .cc-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .cc-nav { display: flex; align-items: center; gap: 8px; }
        .cc-month { min-width: 11rem; text-align: center; font-size: 1.125rem; font-weight: 600; color: var(--cc-ink); }
        .cc-store { min-width: 14rem; }
        .cc-legend { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 0 0 6px; font-size: 12px; color: var(--cc-muted); }
        .cc-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .cc-sw { display: inline-block; width: 12px; height: 12px; border: 1px solid var(--cc-line); border-radius: 4px; }
        .cc-sw.is-today { background: var(--cc-today-bg); border-color: var(--cc-today-bg); border-radius: 50%; }
        .cc-sw.is-full { background: var(--cc-danger-soft); border-color: var(--cc-danger-line); }
        .cc-sw.is-closed { border-style: dashed; background: repeating-linear-gradient(135deg, var(--cc-closed), var(--cc-closed) 3px, transparent 3px, transparent 6px); }
        .cc-sw.is-override { background: var(--cc-warn); border-color: var(--cc-warn); border-radius: 50%; }
        .cc-sw.is-weekly { background: var(--cc-soft); }
        .cc-hint { margin: 0 0 16px; font-size: 12px; color: var(--cc-muted); }
        .cc-weekdays, .cc-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
        .cc-weekdays { padding-bottom: 8px; text-align: center; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--cc-muted); }
        .cc-day {
            appearance: none; display: flex; flex-direction: column; gap: 6px; min-height: 84px; padding: 8px 10px;
            border: 1px solid var(--cc-line); border-radius: 10px; background: var(--cc-surface); color: var(--cc-text);
            font: inherit; text-align: left; transition: border-color .15s, background-color .15s;
        }
        .cc-day:not([disabled]) { cursor: pointer; }
        .cc-day:not([disabled]):hover { border-color: var(--cc-muted); background: var(--cc-hover); }
        .cc-day:focus-visible { outline: 2px solid var(--cc-ink); outline-offset: 2px; }
        .cc-day[disabled] { cursor: default; }
        .cc-day.is-out { opacity: .35; }
        .cc-day.is-past { opacity: .5; }
        .cc-day.is-weekend, .cc-day.is-weekly { background: var(--cc-soft); }
        .cc-day.is-today { border-color: var(--cc-ink); box-shadow: inset 0 0 0 1px var(--cc-ink); }
        .cc-day.is-full { background: var(--cc-danger-soft); border-color: var(--cc-danger-line); }
        .cc-day.is-closed { border-style: dashed; background: repeating-linear-gradient(135deg, var(--cc-closed), var(--cc-closed) 6px, transparent 6px, transparent 12px); }
        .cc-top { display: flex; align-items: center; justify-content: space-between; }
        .cc-num { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; border-radius: 999px; font-size: 13px; font-weight: 600; }
        .is-today .cc-num { background: var(--cc-today-bg); color: var(--cc-today-fg); }
        .cc-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--cc-warn); }
        .cc-count { margin-top: auto; font-size: 15px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .cc-count small { font-size: 12px; font-weight: 500; color: var(--cc-muted); }
        .is-full .cc-count { color: var(--cc-danger); }
        .cc-bar { height: 4px; overflow: hidden; border-radius: 999px; background: var(--cc-bar-bg); }
        .cc-bar > i { display: block; height: 100%; border-radius: inherit; background: var(--cc-bar); }
        .cc-bar > i.is-high { background: var(--cc-warn); }
        .is-full .cc-bar > i { background: var(--cc-danger); }
        .cc-tag { margin-top: auto; font-size: 12px; font-weight: 600; color: var(--cc-text); }
        .cc-tag.is-muted { font-weight: 500; color: var(--cc-muted); }
        .cc-reason { overflow: hidden; font-size: 11px; color: var(--cc-muted); text-overflow: ellipsis; white-space: nowrap; }
        .cc-list { display: flex; flex-direction: column; }
        .cc-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid var(--cc-line); }
        .cc-row:first-child { padding-top: 0; border-top: 0; }
        .cc-row-date { font-weight: 600; color: var(--cc-ink); }
        .cc-row-reason { font-size: 13px; color: var(--cc-muted); }
        .cc-empty { font-size: 14px; color: var(--cc-muted); }
        @media (max-width: 640px) {
            .cc-weekdays, .cc-grid { gap: 4px; }
            .cc-day { min-height: 62px; padding: 6px; }
            .cc-reason { display: none; }
            .cc-count { font-size: 13px; }
            .cc-month { min-width: 8rem; font-size: 1rem; }
            .cc-store { min-width: 0; width: 100%; }
        }
    </style>

    <div class="cc" style="display: flex; flex-direction: column; gap: 24px;">
        <div class="cc-toolbar">
            <div class="cc-nav">
                <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" label="Bulan sebelumnya" wire:click="prevMonth" />
                <h2 class="cc-month">{{ $cc::idDate($month . '-01', 'F Y') }}</h2>
                <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" label="Bulan berikutnya" wire:click="nextMonth" />
                <x-filament::button color="gray" outlined size="sm" wire:click="goToday">Hari Ini</x-filament::button>
            </div>

            @if ($isFullAccess && count($storeOptions) > 1)
                <x-filament::input.wrapper class="cc-store">
                    <x-filament::input.select wire:model.live="storeId" aria-label="Toko">
                        @foreach ($storeOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            @endif
        </div>

        <x-filament::section>
            <div class="cc-legend">
                <span><i class="cc-sw is-today"></i> Hari ini</span>
                <span><i class="cc-sw is-full"></i> Kapasitas penuh</span>
                <span><i class="cc-sw is-closed"></i> Ditutup (tanggal tutup)</span>
                <span><i class="cc-sw is-weekly"></i> Libur rutin mingguan</span>
                <span><i class="cc-sw is-override"></i> Kapasitas diatur manual</span>
            </div>
            <p class="cc-hint">
                Angka = booking yang menempati / kapasitas harian layanan berat (PPF, Kaca Film). Detailing dan Premium Wash dihitung per jam dan diatur di data Toko.
                Libur rutin mingguan diatur di Toko &gt; Jam Operasional.
                @if ($canOpenDay) Klik tanggal (hari ini atau ke depan) untuk mengatur kapasitas atau menutup/membukanya. @endif
            </p>

            <div class="cc-weekdays">
                @foreach ($dayLabels as $label)
                    <div>{{ $label }}</div>
                @endforeach
            </div>

            <div class="cc-grid">
                @foreach ($days as $i => $day)
                    @php
                        $num = (int) substr($day['date'], 8, 2);
                        $isFull = ! $day['closed'] && $day['used'] >= $day['capacity'];
                        $clickable = $canOpenDay && $day['inMonth'] && ! $day['isPast'] && ! $day['weeklyClosed'];
                        $fill = (! $day['closed'] && $day['capacity'] > 0) ? min(100, (int) round($day['used'] / $day['capacity'] * 100)) : 0;
                    @endphp
                    <button
                        type="button"
                        @if ($clickable) wire:click="mountAction('manageDay', { date: '{{ $day['date'] }}' })" @else disabled @endif
                        @if ($day['blocked'] && $day['blockedReason']) title="{{ $day['blockedReason'] }}" @endif
                        @class([
                            'cc-day',
                            'is-out' => ! $day['inMonth'],
                            'is-past' => $day['isPast'] && $day['inMonth'],
                            'is-weekend' => ($i % 7) >= 5 && ! $day['closed'],
                            'is-today' => $day['isToday'],
                            'is-full' => $isFull,
                            'is-closed' => $day['blocked'],
                            'is-weekly' => $day['weeklyClosed'],
                        ])
                    >
                        <span class="cc-top">
                            <span class="cc-num">{{ $num }}</span>
                            @if ($day['hasOverride'] && ! $day['closed'])
                                <span class="cc-dot" title="Kapasitas diatur manual"></span>
                            @endif
                        </span>

                        @if ($day['blocked'])
                            <span class="cc-tag">Ditutup</span>
                            @if ($day['blockedReason'])
                                <span class="cc-reason">{{ $day['blockedReason'] }}</span>
                            @endif
                        @elseif ($day['weeklyClosed'])
                            <span class="cc-tag is-muted">Libur</span>
                        @else
                            <span class="cc-count">{{ $day['used'] }}<small>/{{ $day['capacity'] }}</small></span>
                            <span class="cc-bar"><i @class(['is-high' => ! $isFull && $fill >= 67]) style="width: {{ $fill }}%"></i></span>
                        @endif
                    </button>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Tanggal ditutup yang akan datang</x-slot>
            <x-slot name="description">
                Pelanggan tidak bisa memilih tanggal ini di aplikasi, dan tanggal ini tidak dihitung Alpha maupun jam kerjanya di gaji. Booking yang sudah ada tidak dibatalkan.
            </x-slot>

            @if ($closures->isEmpty())
                <p class="cc-empty">Tidak ada tanggal yang ditutup.</p>
            @else
                <div class="cc-list">
                    @foreach ($closures as $closure)
                        <div class="cc-row">
                            <div>
                                <div class="cc-row-date">{{ $cc::idDate($closure->date, 'l, d F Y') }}</div>
                                <div class="cc-row-reason">{{ $closure->reason ?: 'Tanpa alasan' }}</div>
                            </div>
                            @if ($canReopen)
                                <x-filament::button color="gray" outlined size="sm" wire:click="mountAction('reopenClosure', { id: {{ $closure->id }} })">
                                    Buka Kembali
                                </x-filament::button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
