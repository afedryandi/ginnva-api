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

        // Ringkasan bulan yang sedang dilihat (hanya hari di dalam bulan itu).
        $monthDays = collect($days)->where('inMonth', true);
        $fullDays = $monthDays->filter(fn ($d) => ! $d['closed'] && $d['used'] >= $d['capacity'])->count();
        $closedDays = $monthDays->where('blocked', true)->count();
        $overrideDays = $monthDays->filter(fn ($d) => $d['hasOverride'] && ! $d['closed'])->count();
    @endphp

    {{-- Gaya khusus halaman ini: CSS polos berprefiks .cc- (panel memakai CSS Filament terkompilasi, class Tailwind proyek tidak tersedia). Warna
         memakai variabel Filament (--gray-*, --primary-*, --danger-*) agar ikut warna primer dan mode gelap (.dark). Hanya tampilan: data, aksi, dan izin tidak berubah. --}}
    <style>
        .cc {
            --cc-ink: rgb(var(--gray-900)); --cc-text: rgb(var(--gray-700)); --cc-muted: rgb(var(--gray-500));
            --cc-line: rgba(var(--gray-200), .7); --cc-surface: #fff; --cc-soft: rgba(var(--gray-100), .7); --cc-hover: rgba(var(--gray-100), .9);
            --cc-accent: rgb(var(--primary-600)); --cc-accent-soft: rgba(var(--primary-500), .1); --cc-accent-line: rgba(var(--primary-500), .35);
            --cc-danger: rgb(var(--danger-600)); --cc-danger-soft: rgba(var(--danger-500), .08); --cc-danger-line: rgba(var(--danger-500), .3);
            --cc-warn: #b45309; --cc-warn-bar: #f59e0b; --cc-bar-bg: rgba(var(--gray-200), .8); --cc-bar: rgb(var(--gray-400));
            --cc-closed: rgba(var(--gray-300), .55);
            display: flex; flex-direction: column; gap: 1rem; font-variant-numeric: tabular-nums;
        }
        .dark .cc {
            --cc-ink: rgb(var(--gray-50)); --cc-text: rgb(var(--gray-200)); --cc-muted: rgb(var(--gray-400));
            --cc-line: rgba(255, 255, 255, .08); --cc-surface: rgb(var(--gray-900)); --cc-soft: rgba(255, 255, 255, .04); --cc-hover: rgba(255, 255, 255, .07);
            --cc-accent: rgb(var(--primary-300)); --cc-accent-soft: rgba(var(--primary-400), .14); --cc-accent-line: rgba(var(--primary-400), .4);
            --cc-danger: rgb(var(--danger-400)); --cc-danger-soft: rgba(var(--danger-400), .12); --cc-danger-line: rgba(var(--danger-400), .35);
            --cc-warn: #fcd34d; --cc-bar-bg: rgba(255, 255, 255, .12); --cc-bar: rgb(var(--gray-500)); --cc-closed: rgba(255, 255, 255, .1);
        }

        /* Kartu: sudut 1rem, garis tipis, tanpa bayangan. */
        .cc-card { background: var(--cc-surface); border: 1px solid var(--cc-line); border-radius: 1rem; }

        /* Bilah atas: navigasi bulan di kiri, toko di kanan. */
        .cc-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: .75rem 1rem; }
        .cc-nav { display: flex; align-items: center; gap: .5rem; }
        .cc-month { min-width: 10.5rem; text-align: center; font-size: 1.0625rem; font-weight: 650; letter-spacing: -.01em; color: var(--cc-ink); }
        .cc-store { min-width: 14rem; }

        /* Ringkasan bulan: tiga angka kecil. */
        .cc-stats { display: flex; flex-wrap: wrap; gap: .5rem; }
        .cc-stat { display: inline-flex; align-items: baseline; gap: .5rem; padding: .375rem .75rem; border: 1px solid var(--cc-line); border-radius: 999px; background: var(--cc-surface); font-size: .8125rem; color: var(--cc-muted); }
        .cc-stat b { font-size: .9375rem; font-weight: 650; color: var(--cc-ink); }
        .cc-stat.is-danger b { color: var(--cc-danger); }
        .cc-stat.is-warn b { color: var(--cc-warn); }

        /* Tata letak: kalender di kiri, daftar tanggal ditutup di kanan (menumpuk di layar sempit). */
        .cc-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; align-items: start; }
        @media (min-width: 1280px) { .cc-layout { grid-template-columns: minmax(0, 1fr) 21rem; } }

        .cc-cal { padding: 1.25rem; }
        .cc-legend { display: flex; flex-wrap: wrap; gap: .375rem 1rem; margin: 0 0 .25rem; font-size: .75rem; color: var(--cc-muted); }
        .cc-legend span { display: inline-flex; align-items: center; gap: .375rem; }
        .cc-sw { display: inline-block; width: .75rem; height: .75rem; border: 1px solid var(--cc-line); border-radius: .25rem; }
        .cc-sw.is-today { background: var(--cc-accent); border-color: var(--cc-accent); border-radius: 50%; }
        .cc-sw.is-full { background: var(--cc-danger-soft); border-color: var(--cc-danger-line); }
        .cc-sw.is-closed { border-style: dashed; background: repeating-linear-gradient(135deg, var(--cc-closed), var(--cc-closed) 3px, transparent 3px, transparent 6px); }
        .cc-sw.is-override { background: var(--cc-warn-bar); border-color: var(--cc-warn-bar); border-radius: 50%; }
        .cc-sw.is-weekly { background: var(--cc-soft); }
        .cc-hint { margin: 0 0 1rem; font-size: .75rem; line-height: 1.125rem; color: var(--cc-muted); }

        .cc-weekdays, .cc-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .375rem; }
        .cc-weekdays { padding-bottom: .5rem; text-align: center; font-size: .6875rem; font-weight: 650; letter-spacing: .06em; text-transform: uppercase; color: var(--cc-muted); }

        .cc-day {
            appearance: none; display: flex; flex-direction: column; gap: .375rem; min-height: 5.25rem; padding: .5rem .625rem;
            border: 1px solid var(--cc-line); border-radius: .75rem; background: var(--cc-surface); color: var(--cc-text);
            font: inherit; text-align: left; transition: border-color .15s ease, background-color .15s ease;
        }
        .cc-day:not([disabled]) { cursor: pointer; }
        .cc-day:not([disabled]):hover { border-color: var(--cc-accent-line); background: var(--cc-accent-soft); }
        .cc-day:focus-visible { outline: 2px solid rgb(var(--primary-500)); outline-offset: 2px; }
        .cc-day[disabled] { cursor: default; }
        .cc-day.is-out { opacity: .3; }
        .cc-day.is-past { opacity: .5; }
        .cc-day.is-weekend, .cc-day.is-weekly { background: var(--cc-soft); }
        .cc-day.is-today { border-color: var(--cc-accent-line); }
        .cc-day.is-full { background: var(--cc-danger-soft); border-color: var(--cc-danger-line); }
        .cc-day.is-closed { border-style: dashed; background: repeating-linear-gradient(135deg, var(--cc-closed), var(--cc-closed) 6px, transparent 6px, transparent 12px); }
        .cc-top { display: flex; align-items: center; justify-content: space-between; }
        .cc-num { display: inline-flex; align-items: center; justify-content: center; min-width: 1.5rem; height: 1.5rem; border-radius: 999px; font-size: .8125rem; font-weight: 600; }
        .is-today .cc-num { background: var(--cc-accent); color: #fff; }
        .dark .is-today .cc-num { color: rgb(var(--gray-950)); }
        .cc-dot { width: .5rem; height: .5rem; border-radius: 50%; background: var(--cc-warn-bar); }
        .cc-count { margin-top: auto; font-size: .9375rem; font-weight: 650; color: var(--cc-ink); }
        .cc-count small { font-size: .75rem; font-weight: 500; color: var(--cc-muted); }
        .is-full .cc-count { color: var(--cc-danger); }
        .cc-bar { height: .25rem; overflow: hidden; border-radius: 999px; background: var(--cc-bar-bg); }
        .cc-bar > i { display: block; height: 100%; border-radius: inherit; background: var(--cc-bar); }
        .cc-bar > i.is-high { background: var(--cc-warn-bar); }
        .is-full .cc-bar > i { background: var(--cc-danger); }
        .cc-tag { margin-top: auto; font-size: .75rem; font-weight: 600; color: var(--cc-text); }
        .cc-tag.is-muted { font-weight: 500; color: var(--cc-muted); }
        .cc-reason { overflow: hidden; font-size: .6875rem; color: var(--cc-muted); text-overflow: ellipsis; white-space: nowrap; }

        /* Daftar tanggal ditutup. */
        .cc-side { padding: 1.25rem; }
        .cc-side-title { font-size: .875rem; font-weight: 650; color: var(--cc-ink); }
        .cc-side-desc { margin: .25rem 0 1rem; font-size: .75rem; line-height: 1.125rem; color: var(--cc-muted); }
        .cc-list { display: flex; flex-direction: column; }
        .cc-row { display: flex; align-items: center; gap: .75rem; padding: .625rem 0; border-top: 1px solid var(--cc-line); }
        .cc-row:first-child { padding-top: 0; border-top: 0; }
        .cc-row-badge { display: flex; flex-direction: column; align-items: center; justify-content: center; flex-shrink: 0; width: 2.75rem; height: 2.75rem; border-radius: .75rem; background: var(--cc-soft); }
        .cc-row-badge b { font-size: 1rem; line-height: 1.1; font-weight: 650; color: var(--cc-ink); }
        .cc-row-badge small { font-size: .625rem; font-weight: 650; letter-spacing: .05em; text-transform: uppercase; color: var(--cc-muted); }
        .cc-row-main { flex: 1 1 auto; min-width: 0; }
        .cc-row-date { font-size: .8125rem; font-weight: 600; color: var(--cc-ink); }
        .cc-row-reason { overflow: hidden; font-size: .75rem; color: var(--cc-muted); text-overflow: ellipsis; white-space: nowrap; }
        .cc-empty { padding: 1.5rem .5rem; text-align: center; font-size: .8125rem; color: var(--cc-muted); }

        @media (max-width: 640px) {
            .cc-cal, .cc-side { padding: 1rem; }
            .cc-weekdays, .cc-grid { gap: .25rem; }
            .cc-day { min-height: 3.875rem; padding: .375rem; }
            .cc-reason { display: none; }
            .cc-count { font-size: .8125rem; }
            .cc-month { min-width: 8rem; font-size: 1rem; }
            .cc-store { min-width: 0; width: 100%; }
        }
    </style>

    <div class="cc">
        <div class="cc-card cc-toolbar">
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

        <div class="cc-stats">
            <span class="cc-stat {{ $fullDays > 0 ? 'is-danger' : '' }}"><b>{{ $fullDays }}</b> hari kapasitas penuh</span>
            <span class="cc-stat"><b>{{ $closedDays }}</b> tanggal ditutup</span>
            <span class="cc-stat {{ $overrideDays > 0 ? 'is-warn' : '' }}"><b>{{ $overrideDays }}</b> kapasitas diatur manual</span>
        </div>

        <div class="cc-layout">
            <div class="cc-card cc-cal">
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
            </div>

            <div class="cc-card cc-side">
                <div class="cc-side-title">Tanggal ditutup yang akan datang</div>
                <p class="cc-side-desc">
                    Pelanggan tidak bisa memilih tanggal ini di aplikasi, dan tanggal ini tidak dihitung Alpha maupun jam kerjanya di gaji. Booking yang sudah ada tidak dibatalkan.
                </p>

                @if ($closures->isEmpty())
                    <p class="cc-empty">Tidak ada tanggal yang ditutup.</p>
                @else
                    <div class="cc-list">
                        @foreach ($closures as $closure)
                            <div class="cc-row">
                                <div class="cc-row-badge">
                                    <b>{{ $cc::idDate($closure->date, 'd') }}</b>
                                    <small>{{ $cc::idDate($closure->date, 'M') }}</small>
                                </div>
                                <div class="cc-row-main">
                                    <div class="cc-row-date">{{ $cc::idDate($closure->date, 'l, d F Y') }}</div>
                                    <div class="cc-row-reason">{{ $closure->reason ?: 'Tanpa alasan' }}</div>
                                </div>
                                @if ($canReopen)
                                    <x-filament::button color="gray" outlined size="xs" wire:click="mountAction('reopenClosure', { id: {{ $closure->id }} })">
                                        Buka
                                    </x-filament::button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
