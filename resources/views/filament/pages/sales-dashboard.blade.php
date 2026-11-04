<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini (warna naik/turun, banner, angka tabular, beberapa jarak). Grid memakai style inline di elemennya. --}}
    @once
        <style>
            .sd-up { color: #15803d; } .sd-down { color: #b91c1c; } .sd-warn { color: #b45309; }
            .dark .sd-up { color: #4ade80; } .dark .sd-down { color: #f87171; } .dark .sd-warn { color: #fbbf24; }
            .sd-num { font-variant-numeric: sd-num; }
            .sd-caps { text-transform: uppercase; letter-spacing: .05em; }
            .sd-noshrink { flex-shrink: 0; }
            .sd-rank { background: #f3f4f6; color: #6b7280; font-size: 11px; font-weight: 700; }
            .dark .sd-rank { background: rgba(255, 255, 255, .1); color: #9ca3af; }
            .sd-banner { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid; border-radius: 8px; font-size: 14px; text-decoration: none; }
            .sd-banner svg { flex-shrink: 0; }
            .sd-banner-warn { background: #fffbeb; border-color: #fcd34d; color: #92400e; }
            .sd-banner-warn:hover { background: #fef3c7; }
            .dark .sd-banner-warn { background: rgba(245, 158, 11, .1); border-color: rgba(245, 158, 11, .3); color: #fcd34d; }
            .dark .sd-banner-warn:hover { background: rgba(245, 158, 11, .2); }
            .sd-banner-ok { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
            .dark .sd-banner-ok { background: rgba(34, 197, 94, .1); border-color: rgba(34, 197, 94, .3); color: #4ade80; }
            .sd-banner-bad { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
            .dark .sd-banner-bad { background: rgba(239, 68, 68, .1); border-color: rgba(239, 68, 68, .3); color: #f87171; }
            .mt-4 { margin-top: 1rem; } .py-10 { padding-top: 2.5rem; padding-bottom: 2.5rem; } .py-1\.5 { padding-top: .375rem; padding-bottom: .375rem; }
            .gap-1\.5 { gap: .375rem; } .gap-0\.5 { gap: .125rem; } .h-3\.5 { height: .875rem; } .w-3\.5 { width: .875rem; }
        </style>
    @endonce
    @php
        $result = $this->getResult();
        $current = $result['current'];
        $previous = $result['previous'];
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        // Modul Keuangan mati (Accurate 5): piutang, jurnal, dan laba-rugi bukan bagian sistem ini, jadi kartunya disembunyikan dan teksnya disesuaikan.
        $financeOn = (bool) config('features.finance');
        $avgOf = fn ($total, $count) => $count > 0 ? $total / $count : 0;

        // Angka BERSIH = tercatat − pengembalian (refund). Ini yang jadi
        // headline supaya "Total Penjualan" = "Penjualan Bersih" di P&L.
        $currentNet = $current['revenue'] - $current['refund'];
        $previousNet = $previous['revenue'] - $previous['refund'];

        $currentAvg = $avgOf($currentNet, $current['count']);
        $previousAvg = $avgOf($previousNet, $previous['count']);
        $currentProductAvg = $avgOf($current['productsSold'], $current['count']);
        $previousProductAvg = $avgOf($previous['productsSold'], $previous['count']);

        $isEmpty = $current['count'] === 0;

        // Badge %perubahan gaya Majoo ("↓92,88%") — periode pembanding 0
        // dianggap tidak ada pembanding (bukan "naik tak terhingga%").
        $change = function ($curr, $prev) {
            if ($prev <= 0) {
                return null;
            }
            $percent = (($curr - $prev) / $prev) * 100;
            return ['value' => number_format(abs($percent), 2, ',', '.'), 'up' => $percent >= 0];
        };

        // Drill-down ke "Detail Penjualan" (SalesResource) dengan filter
        // rentang tanggal jurnal + cabang yang sama seperti dashboard.
        [$dStart, $dEnd] = $this->currentRange();
        $effectiveStoreId = $this->effectiveStoreId();
        $drillFilters = ['entry_date' => ['from' => $dStart->toDateString(), 'until' => $dEnd->toDateString()]];
        if ($effectiveStoreId) {
            $drillFilters['store_id'] = ['value' => $effectiveStoreId];
        }
        $drillUrl = \App\Filament\Resources\SalesResource::getUrl('index', ['tableFilters' => $drillFilters]);

        // Jembatan ke "Ringkasan Penjualan" (audit 2026-09-11, temuan #7)
        // — bawa rentang tanggal yang SAMA persis yang sedang dilihat di
        // sini, bukan cuma lempar ke bulan berjalan.
        $summaryReportUrl = \App\Filament\Pages\SalesSummaryReport::getUrl([
            'from' => $dStart->toDateString(),
            'to' => $dEnd->toDateString(),
        ]);

        $storeOptions = $this->getStoreOptions();
        $canFilterStore = (auth()->user()?->isFullAccess() ?? false) && count($storeOptions) > 1;

        // Ringkasan kecil "perlu perhatian" (audit 2026-09-11, temuan #6)
        // — GANTI 2 widget tabel penuh yang sebelumnya nempel di bawah
        // Dashboard Penjualan (domainnya Inventori, bukan Penjualan).
        // Query yang SAMA dipakai widget aslinya (needingAttentionQuery(),
        // ditarik keluar dari table() masing-masing) supaya angkanya
        // tidak pernah menyimpang dari Dashboard Inventaris.
        $materialsAttentionCount = \App\Filament\Pages\InventoryDashboard::canAccess() && \App\Filament\InventoryWidgets\MaterialsNeedingAttentionWidget::canView()
            ? \App\Filament\InventoryWidgets\MaterialsNeedingAttentionWidget::needingAttentionQuery()->count()
            : null;
        $consumablesAttentionCount = \App\Filament\Pages\InventoryDashboard::canAccess() && \App\Filament\InventoryWidgets\ConsumablesNeedingAttentionWidget::canView()
            ? \App\Filament\InventoryWidgets\ConsumablesNeedingAttentionWidget::needingAttentionQuery()->count()
            : null;
    @endphp

    @if ($result['pendingCount'] > 0)
        <a
            href="{{ \App\Filament\Resources\BookingResource::getUrl('index', ['tableFilters' => ['selesai_belum_diproses' => ['isActive' => true]]]) }}"
            class="sd-banner sd-banner-warn"
        >
            <x-heroicon-o-exclamation-triangle class="h-4 w-4 sd-noshrink" />
            <span>
                <strong class="font-semibold">{{ number_format($result['pendingCount'], 0, ',', '.') }} booking</strong>
                sudah selesai tapi belum diproses ke pendapatan — nominalnya belum masuk angka di bawah.
            </span>
            <x-heroicon-o-arrow-right class="ml-auto h-4 w-4 sd-noshrink" />
        </a>
    @endif

    {{--
        Toggle periode + navigasi tanggal + lompat-ke-tanggal + filter
        cabang. wire:target di baris ini SENGAJA menyebut nama method/
        property yang benar-benar mengubah hasil (bukan "target semua
        request") — supaya spinner cuma nongol untuk aksi yang relevan.
    --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-lg border border-gray-200 p-1 dark:border-white/10">
            @foreach (['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan'] as $key => $label)
                <button
                    type="button"
                    wire:click="setPeriod('{{ $key }}')"
                    wire:loading.attr="disabled"
                    wire:target="setPeriod('{{ $key }}')"
                    aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                    @class([
                        'rounded-md px-4 py-1.5 text-sm font-medium transition',
                        'bg-primary-600 text-white' => $period === $key,
                        'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $period !== $key,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>

        <div class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 dark:border-white/10">
            <button
                type="button"
                wire:click="goPrev"
                wire:loading.attr="disabled"
                wire:target="goPrev"
                aria-label="Periode sebelumnya"
                class="text-gray-500 hover:text-gray-800 disabled:opacity-40 dark:text-gray-400 dark:hover:text-white"
            >
                <x-heroicon-o-chevron-left class="h-4 w-4" />
            </button>
            <span class="text-center text-sm font-medium" style="display: inline-block; min-width: 9rem">
                <span wire:loading.remove wire:target="setPeriod, goPrev, goNext, referenceDate, storeId">{{ $this->getRangeLabel() }}</span>
                <span wire:loading wire:target="setPeriod, goPrev, goNext, referenceDate, storeId" class="inline-flex items-center justify-center gap-1.5 text-gray-400 dark:text-gray-500">
                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    Memuat…
                </span>
            </span>
            <button
                type="button"
                wire:click="goNext"
                wire:loading.attr="disabled"
                wire:target="goNext"
                aria-label="Periode berikutnya"
                @disabled(! $this->canGoNext())
                @class(['text-gray-500 hover:text-gray-800 disabled:opacity-40 dark:text-gray-400 dark:hover:text-white' => $this->canGoNext(), 'text-gray-300 dark:text-gray-700' => ! $this->canGoNext()])
            >
                <x-heroicon-o-chevron-right class="h-4 w-4" />
            </button>
        </div>

        <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm dark:border-white/10">
            <x-heroicon-o-calendar-days class="h-4 w-4 text-gray-500 dark:text-gray-400" />
            <span class="text-gray-600 dark:text-gray-300">Lompat ke</span>
            <input
                type="date"
                wire:model.live="referenceDate"
                max="{{ now()->toDateString() }}"
                class="border-0 bg-transparent p-0 text-sm font-medium outline-none focus:outline-none focus:ring-0 dark:[color-scheme:dark]" style="border: 0; background-color: transparent; box-shadow: none; outline: none; padding: 0; font: inherit; font-size: 0.875rem; font-weight: 500; color: inherit;"
            />
        </label>

        @if ($canFilterStore)
            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm dark:border-white/10">
                <x-heroicon-o-building-storefront class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                <select
                    wire:model.live="storeId"
                    class="border-0 bg-transparent p-0 pr-7 text-sm font-medium outline-none focus:outline-none focus:ring-0 dark:[color-scheme:dark]" style="border: 0; background-color: transparent; box-shadow: none; outline: none; padding: 0 1.75rem 0 0; font: inherit; font-size: 0.875rem; font-weight: 500; color: inherit;"
                >
                    <option value="">Semua toko</option>
                    @foreach ($storeOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>

    <x-filament::section
        wire:loading.class="opacity-50 pointer-events-none"
        wire:target="setPeriod, goPrev, goNext, referenceDate, storeId"
    >
        @if ($isEmpty)
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <x-heroicon-o-document-magnifying-glass class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada penjualan tercatat untuk {{ $this->getRangeLabel() }}.</p>
                <p class="max-w-md text-xs text-gray-500 dark:text-gray-400">
                    Angka baru muncul setelah booking selesai diproses lewat "Proses Referral"{{ $financeOn ? ' ke Jurnal Umum' : '' }}.
                    @if ($result['pendingCount'] > 0)
                        Ada {{ number_format($result['pendingCount'], 0, ',', '.') }} booking selesai yang menunggu diproses (lihat peringatan di atas).
                    @endif
                </p>
            </div>
        @else
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px">
            <div>
                <x-metric-label :tooltip="$financeOn ? 'Booking yang sudah tercatat ke Jurnal Umum, dikurangi refund periode ini. Sama dengan Penjualan Bersih di Laba-Rugi.' : 'Booking yang penjualannya sudah diproses lewat Proses Referral, dikurangi pengembalian periode ini.'">Total Penjualan</x-metric-label>
                @php $c = $change($currentNet, $previousNet); @endphp
                @if ($c)
                    <span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $c['up'] ? 'sd-up' : 'sd-down' }}">
                        @if ($c['up']) <x-heroicon-m-arrow-up class="h-3 w-3" /> @else <x-heroicon-m-arrow-down class="h-3 w-3" /> @endif
                        {{ $c['value'] }}%
                    </span>
                @endif
                <a href="{{ $drillUrl }}" class="mt-1 block text-3xl font-bold sd-num hover:text-primary-600 hover:underline dark:hover:text-primary-400">{{ $rupiah($currentNet) }}</a>
                @if ($current['refund'] > 0)
                    <div class="mt-1 text-xs sd-down">setelah pengembalian {{ $rupiah($current['refund']) }} (kotor {{ $rupiah($current['revenue']) }})</div>
                @endif
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Akumulasi dari Awal Bulan {{ $rupiah($result['monthToDateRevenue']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Proyeksi Bulan Ini {{ $rupiah($result['projection']) }}</div>
            </div>

            @if ($financeOn)
            <div>
                <x-metric-label tooltip="transaction_amount − amount_received untuk booking periode ini. amount_received kosong dianggap lunas penuh.">Penjualan Belum Dibayar (Piutang)</x-metric-label>
                <a href="{{ $drillUrl }}" class="mt-1 block text-2xl font-bold sd-num hover:underline {{ $current['outstanding'] > 0 ? 'sd-down' : '' }}">{{ $rupiah($current['outstanding']) }}</a>
                <div class="mt-3">
                    <x-metric-label tooltip="amount_received yang sudah diterima dari booking periode ini (transaction_amount penuh kalau kolom kosong).">Penjualan Terbayar</x-metric-label>
                </div>
                <div class="mt-1 text-xl font-semibold sd-num sd-up">{{ $rupiah($current['received']) }}</div>
            </div>
            @endif

            <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px">
                <div>
                    <x-metric-label tooltip="Jumlah booking yang tercatat sebagai pendapatan periode ini (lunas maupun masih piutang).">Transaksi</x-metric-label>
                    @php $c = $change($current['count'], $previous['count']); @endphp
                    @if ($c)<span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $c['up'] ? 'sd-up' : 'sd-down' }}">@if ($c['up'])<x-heroicon-m-arrow-up class="h-3 w-3" />@else<x-heroicon-m-arrow-down class="h-3 w-3" />@endif{{ $c['value'] }}%</span>@endif
                    <a href="{{ $drillUrl }}" class="block text-lg font-bold sd-num hover:text-primary-600 hover:underline dark:hover:text-primary-400">{{ number_format($current['count'], 0, ',', '.') }}</a>
                </div>
                <div>
                    <x-metric-label tooltip="Total Penjualan dibagi Transaksi — rata-rata nilai per booking periode ini.">Penjualan per Transaksi</x-metric-label>
                    @php $c = $change($currentAvg, $previousAvg); @endphp
                    @if ($c)<span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $c['up'] ? 'sd-up' : 'sd-down' }}">@if ($c['up'])<x-heroicon-m-arrow-up class="h-3 w-3" />@else<x-heroicon-m-arrow-down class="h-3 w-3" />@endif{{ $c['value'] }}%</span>@endif
                    <div class="text-lg font-bold sd-num">{{ $rupiah($currentAvg) }}</div>
                </div>
                <div>
                    <x-metric-label tooltip="Jumlah jenis produk/jasa periode ini. Satu booking Kaca Film + PPF + Detailing dihitung 3.">Produk Terjual</x-metric-label>
                    @php $c = $change($current['productsSold'], $previous['productsSold']); @endphp
                    @if ($c)<span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $c['up'] ? 'sd-up' : 'sd-down' }}">@if ($c['up'])<x-heroicon-m-arrow-up class="h-3 w-3" />@else<x-heroicon-m-arrow-down class="h-3 w-3" />@endif{{ $c['value'] }}%</span>@endif
                    <div class="text-lg font-bold sd-num">{{ number_format($current['productsSold'], 0, ',', '.') }}</div>
                </div>
                <div>
                    <x-metric-label tooltip="Produk Terjual dibagi Transaksi — rata-rata jumlah produk per booking periode ini.">Produk per Transaksi</x-metric-label>
                    @php $c = $change($currentProductAvg, $previousProductAvg); @endphp
                    @if ($c)<span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $c['up'] ? 'sd-up' : 'sd-down' }}">@if ($c['up'])<x-heroicon-m-arrow-up class="h-3 w-3" />@else<x-heroicon-m-arrow-down class="h-3 w-3" />@endif{{ $c['value'] }}%</span>@endif
                    <div class="text-lg font-bold sd-num">{{ number_format($currentProductAvg, 2, ',', '.') }}</div>
                </div>
            </div>
        </div>

        @if ($result['growthHasComparison'])
            @php
                $up = $result['growthDelta'] >= 0;
            @endphp
            <div @class(['sd-banner', 'sd-banner-ok' => $up, 'sd-banner-bad' => ! $up]) style="margin-top: 16px">
                @if ($up)
                    <x-heroicon-o-arrow-trending-up class="h-4 w-4 sd-noshrink" />
                @else
                    <x-heroicon-o-arrow-trending-down class="h-4 w-4 sd-noshrink" />
                @endif
                <span>
                    Penjualan bulan ini {{ $up ? 'meningkat' : 'menurun' }} senilai
                    <strong class="font-semibold">{{ $rupiah(abs($result['growthDelta'])) }}</strong>
                    dibanding periode yang sama bulan lalu.
                </span>
            </div>
        @endif

        @php
            $kpis = $this->getKpis();
            $pct = fn ($n) => number_format($n, 1, ',', '.') . '%';
        @endphp
        <div class="mt-6 border-t border-gray-200 pt-4 dark:border-white/10">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Indikator Penjualan</h3>
            @if ($kpis['count'] === 0)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Belum ada penjualan tercatat pada periode ini.</p>
            @else
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-top: 12px">
                    <div>
                        <x-metric-label tooltip="Booking yang punya lebih dari satu jenis layanan (mis. PPF + Kaca Film, atau layanan + Premium Wash) dibagi seluruh transaksi periode ini.">Transaksi Multi-Layanan</x-metric-label>
                        <div class="mt-1 text-lg font-bold sd-num">{{ $pct($kpis['multi_service']['pct']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $kpis['multi_service']['count'] }} dari {{ $kpis['count'] }} transaksi</div>
                    </div>
                    <div>
                        <x-metric-label tooltip="Pelanggan periode ini yang sebelumnya pernah punya penjualan tercatat, dibagi seluruh pelanggan periode ini. Memperhitungkan semua toko.">Pelanggan Kembali</x-metric-label>
                        <div class="mt-1 text-lg font-bold sd-num">{{ $pct($kpis['customers']['pct']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $kpis['customers']['returning'] }} dari {{ $kpis['customers']['total'] }} pelanggan</div>
                    </div>
                    <div>
                        <x-metric-label tooltip="Voucher + promo total pembelian + potongan baris dan nego, dibagi nilai sebelum potongan. Potongan baris/nego hanya terhitung untuk booking yang punya rincian harga.">Potongan Diberikan</x-metric-label>
                        <div class="mt-1 text-lg font-bold sd-num">{{ $rupiah($kpis['discounts']['total']) }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">({{ $pct($kpis['discounts']['pct_of_gross']) }})</span></div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Voucher {{ $rupiah($kpis['discounts']['voucher']) }} · Promo {{ $rupiah($kpis['discounts']['promo']) }} · Potongan/nego {{ $rupiah($kpis['discounts']['other']) }}</div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; margin-top: 16px">
                    <div>
                        <div class="text-xs font-semibold sd-caps text-gray-500 dark:text-gray-400">Penjualan per Jenis Layanan</div>
                        <table class="mt-2 w-full text-sm sd-num">
                            @foreach ($kpis['by_type'] as $row)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="py-1 pe-3">{{ $row['label'] }}</td>
                                    <td class="py-1 pe-3 text-right text-gray-500 dark:text-gray-400">{{ $row['count'] }} transaksi</td>
                                    <td class="py-1 pe-3 text-right">{{ $rupiah($row['revenue']) }}</td>
                                    <td class="py-1 text-right text-gray-500 dark:text-gray-400">{{ $pct($row['pct']) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                    @if (count($kpis['by_store']) > 1)
                        <div>
                            <div class="text-xs font-semibold sd-caps text-gray-500 dark:text-gray-400">Penjualan per Toko</div>
                            <table class="mt-2 w-full text-sm sd-num">
                                @foreach ($kpis['by_store'] as $row)
                                    <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                        <td class="py-1 pe-3">{{ $row['name'] }}</td>
                                        <td class="py-1 pe-3 text-right text-gray-500 dark:text-gray-400">{{ $row['count'] }} transaksi</td>
                                        <td class="py-1 pe-3 text-right">{{ $rupiah($row['revenue']) }}</td>
                                        <td class="py-1 text-right text-gray-500 dark:text-gray-400">rata-rata {{ $rupiah($row['avg']) }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    @endif
                </div>

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Rincian harga tercatat pada {{ $kpis['lines_coverage']['with_lines'] }} dari {{ $kpis['count'] }} transaksi ({{ $pct($kpis['lines_coverage']['pct']) }}).
                    @if ($kpis['lines_coverage']['pct'] < 100)
                        Transaksi tanpa rincian dibagi rata antar jenis layanan dan tidak punya data potongan baris/nego, jadi angka di atas akan makin akurat seiring rincian diisi.
                    @endif
                </p>
            @endif
        </div>

        <details class="mt-4 rounded-lg border border-gray-200 text-sm dark:border-white/10">
            <summary class="cursor-pointer px-3 py-2 font-medium text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                Cara baca angka
            </summary>
            <div class="space-y-2 border-t border-gray-200 px-3 py-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                <p><strong class="text-gray-700 dark:text-gray-200">Total Penjualan</strong> — jumlah <code>transaction_amount</code> dari booking {{ $financeOn ? 'yang sudah tercatat ke Jurnal Umum (sudah lewat "Proses Referral")' : 'yang penjualannya sudah diproses lewat "Proses Referral"' }}, dikurangi nominal refund yang diproses pada periode ini. Sama definisi dengan "Penjualan Bersih" di Ringkasan Penjualan{{ $financeOn ? ' &amp; Laba-Rugi' : '' }}. Booking selesai yang belum diproses tidak ikut; booking dibatalkan tidak mungkin masuk (tidak punya jurnal).</p>
                @if ($financeOn)
                <p><strong class="text-gray-700 dark:text-gray-200">Piutang</strong> — <code>transaction_amount − amount_received</code>. Kolom <code>amount_received</code> yang kosong dianggap lunas penuh, jadi hanya booking yang eksplisit belum lunas yang masuk sini.</p>
                @endif
                @if ($financeOn)
                <p><strong class="text-gray-700 dark:text-gray-200">Penjualan Terbayar</strong> — <code>amount_received</code> yang sudah diterima (atau <code>transaction_amount</code> penuh kalau kolom kosong).</p>
                @endif
                <p><strong class="text-gray-700 dark:text-gray-200">Transaksi</strong> — jumlah booking yang tercatat sebagai pendapatan periode ini, lunas maupun masih piutang.</p>
                <p><strong class="text-gray-700 dark:text-gray-200">Produk Terjual</strong> — jumlah jenis produk/jasa yang tercakup. Satu booking dengan Kaca Film + PPF + Detailing sekaligus dihitung 3, sesuai flag <code>product_kaca_film</code> / <code>product_ppf</code> / <code>product_detailing</code>.</p>
                <p><strong class="text-gray-700 dark:text-gray-200">Akumulasi dari Awal Bulan</strong> &amp; <strong class="text-gray-700 dark:text-gray-200">Proyeksi Bulan Ini</strong> — selalu dihitung dari bulan kalender berjalan, tidak mengikuti filter periode di atas.</p>
                <p>Badge <x-heroicon-m-arrow-up class="inline h-3 w-3" /> / <x-heroicon-m-arrow-down class="inline h-3 w-3" /> membandingkan periode ini dengan periode sebelumnya yang sama panjang. Kalau periode pembanding nol, badge tidak ditampilkan.</p>
            </div>
        </details>

        {{-- Jembatan ke Ringkasan Penjualan (audit 2026-09-11, #7) — bukan
             export sendiri di sini, cukup nyambung ke halaman yang sudah
             punya tombol Export Excel/PDF, bawa rentang tanggal yang sama. --}}
        <a
            href="{{ $summaryReportUrl }}"
            class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
        >
            <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
            Lihat &amp; Export di Ringkasan Penjualan
        </a>

        {{-- "Ranking Installer" (audit Majoo f1) — top 5 periode berjalan,
             diurutkan nilai penjualan tertinggi. Nilai "Penjualan" di sini
             adalah nilai transaksi booking yang ditugaskan (bukan
             komisinya) — sama definisi seperti Laporan Komisi Teknisi. --}}
        @php $ranking = $this->getTechnicianRanking(); @endphp
        @if (! empty($ranking))
            <div class="mt-6 border-t border-gray-200 pt-4 dark:border-white/10">
                <x-metric-label tooltip="Top 5 installer periode ini berdasar nilai transaksi booking yang ditugaskan ke mereka (Booking::installers) — bukan nominal komisi. Booking tim dihitung penuh ke masing-masing installer, sama pola dengan Laporan Komisi Installer.">Ranking Installer</x-metric-label>
                <div class="mt-2 divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($ranking as $i => $row)
                        <div class="flex items-center justify-between gap-3 py-2 text-sm">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="flex h-5 w-5 sd-noshrink items-center justify-center rounded-full sd-rank">{{ $i + 1 }}</span>
                                <span class="truncate font-medium">{{ $row['name'] }}</span>
                            </div>
                            <div class="flex sd-noshrink items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ number_format($row['jobCount'], 0, ',', '.') }} job</span>
                                <span class="font-semibold sd-num text-gray-700 dark:text-gray-200">{{ $rupiah($row['salesTotal']) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @endif
    </x-filament::section>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Grafik di bawah selalu menampilkan bulan kalender berjalan (perbandingan dengan bulan lalu) —
        tidak mengikuti filter periode/tanggal di atas, tapi TETAP ikut filter toko.
    </p>

    {{--
        @livewire() langsung (bukan <x-filament-widgets::widgets>) supaya
        bisa kirim $effectiveStoreId ke mount() masing-masing chart (audit
        2026-09-11, temuan #2 — sebelumnya kedua chart ini selalu
        company-wide, tidak ikut filter cabang di atas). wire:key SENGAJA
        cuma menyebut storeId (bukan period/referenceDate juga) — data
        chart memang dikunci ke bulan kalender berjalan, remount tak perlu
        dipicu oleh perubahan periode/tanggal, cukup oleh ganti cabang.
    --}}
    <div class="grid grid-cols-1 gap-6">
        @livewire(\App\Filament\Widgets\BookingRevenueTrendChart::class, ['storeId' => $effectiveStoreId], key('sales-dashboard-trend-' . ($effectiveStoreId ?? 'all')))
        @livewire(\App\Filament\Widgets\BookingRevenueByCategoryChart::class, ['storeId' => $effectiveStoreId], key('sales-dashboard-category-' . ($effectiveStoreId ?? 'all')))
        @livewire(\App\Filament\Widgets\BookingRevenueByPaymentMethodChart::class, ['storeId' => $effectiveStoreId], key('sales-dashboard-payment-method-' . ($effectiveStoreId ?? 'all')))
    </div>

    {{--
        "Perlu Perhatian" (audit 2026-09-11, temuan #6) — SEBELUMNYA 2
        widget tabel Inventori penuh nempel di sini, padahal domainnya
        Inventori bukan Penjualan. Diganti jadi ringkasan angka + link ke
        Dashboard Inventaris (query count DITARIK dari widget aslinya,
        lihat needingAttentionQuery() — angkanya tidak pernah menyimpang
        dari tabel yang sebenarnya).
    --}}
    @if ($materialsAttentionCount !== null || $consumablesAttentionCount !== null)
        <a
            href="{{ \App\Filament\Pages\InventoryDashboard::getUrl() }}"
            class="flex flex-wrap items-center gap-4 rounded-lg border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5"
        >
            <span class="font-medium text-gray-700 dark:text-gray-200">Persediaan perlu perhatian:</span>
            @if ($materialsAttentionCount !== null)
                <span @class([
                    'inline-flex items-center gap-1',
                    'sd-warn' => $materialsAttentionCount > 0,
                    'text-gray-400 dark:text-gray-500' => $materialsAttentionCount === 0,
                ])>
                    <x-heroicon-o-beaker class="h-4 w-4" />
                    {{ number_format($materialsAttentionCount, 0, ',', '.') }} bahan baku
                </span>
            @endif
            @if ($consumablesAttentionCount !== null)
                <span @class([
                    'inline-flex items-center gap-1',
                    'sd-warn' => $consumablesAttentionCount > 0,
                    'text-gray-400 dark:text-gray-500' => $consumablesAttentionCount === 0,
                ])>
                    <x-heroicon-o-cube class="h-4 w-4" />
                    {{ number_format($consumablesAttentionCount, 0, ',', '.') }} barang habis pakai
                </span>
            @endif
            <x-heroicon-o-arrow-right class="ml-auto h-4 w-4 sd-noshrink text-gray-400" />
        </a>
    @endif
</x-filament-panels::page>
