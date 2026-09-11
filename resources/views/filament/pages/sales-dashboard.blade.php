<x-filament-panels::page>
    @php
        $result = $this->getResult();
        $current = $result['current'];
        $previous = $result['previous'];
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
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
        $drillFilters = ['entry_date' => ['from' => $dStart->toDateString(), 'until' => $dEnd->toDateString()]];
        if ($this->storeId) {
            $drillFilters['store_id'] = ['value' => $this->storeId];
        }
        $drillUrl = \App\Filament\Resources\SalesResource::getUrl('index', ['tableFilters' => $drillFilters]);

        $storeOptions = $this->getStoreOptions();
        $canFilterStore = (auth()->user()?->isFullAccess() ?? false) && count($storeOptions) > 1;
    @endphp

    @if ($result['pendingCount'] > 0)
        <a
            href="{{ \App\Filament\Resources\BookingResource::getUrl('index', ['tableFilters' => ['selesai_belum_diproses' => ['isActive' => true]]]) }}"
            class="flex items-center gap-2 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-800 transition hover:bg-warning-100 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300 dark:hover:bg-warning-500/20"
        >
            <x-heroicon-o-exclamation-triangle class="h-4 w-4 flex-shrink-0" />
            <span>
                <strong class="font-semibold">{{ number_format($result['pendingCount'], 0, ',', '.') }} booking</strong>
                sudah selesai tapi belum diproses ke pendapatan — nominalnya belum masuk angka di bawah.
            </span>
            <x-heroicon-o-arrow-right class="ml-auto h-4 w-4 flex-shrink-0" />
        </a>
    @endif

    {{-- Toggle periode + navigasi tanggal + lompat-ke-tanggal + filter cabang --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-lg border border-gray-200 p-1 dark:border-white/10">
            @foreach (['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan'] as $key => $label)
                <button
                    type="button"
                    wire:click="setPeriod('{{ $key }}')"
                    @class([
                        'rounded-md px-4 py-1.5 text-sm font-medium transition',
                        'bg-primary-600 text-white' => $period === $key,
                        'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $period !== $key,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>

        <div class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 dark:border-white/10">
            <button type="button" wire:click="goPrev" class="text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white">
                <x-heroicon-o-chevron-left class="h-4 w-4" />
            </button>
            <span class="min-w-[9rem] text-center text-sm font-medium">{{ $this->getRangeLabel() }}</span>
            <button
                type="button"
                wire:click="goNext"
                @disabled(! $this->canGoNext())
                @class(['text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white' => $this->canGoNext(), 'text-gray-300 dark:text-gray-700' => ! $this->canGoNext()])
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
                class="border-0 bg-transparent p-0 text-sm font-medium focus:ring-0 dark:[color-scheme:dark]"
            />
        </label>

        @if ($canFilterStore)
            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm dark:border-white/10">
                <x-heroicon-o-building-storefront class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                <select
                    wire:model.live="storeId"
                    class="border-0 bg-transparent p-0 pr-7 text-sm font-medium focus:ring-0 dark:[color-scheme:dark]"
                >
                    <option value="">Semua cabang</option>
                    @foreach ($storeOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>

    <x-filament::section>
        @if ($isEmpty)
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <x-heroicon-o-document-magnifying-glass class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada penjualan tercatat untuk {{ $this->getRangeLabel() }}.</p>
                <p class="max-w-md text-xs text-gray-500 dark:text-gray-400">
                    Angka baru muncul setelah booking selesai diproses lewat "Proses Referral" ke Jurnal Umum.
                    @if ($result['pendingCount'] > 0)
                        Ada {{ number_format($result['pendingCount'], 0, ',', '.') }} booking selesai yang menunggu diproses (lihat peringatan di atas).
                    @endif
                </p>
            </div>
        @else
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            <div>
                <x-metric-label tooltip="Booking yang sudah tercatat ke Jurnal Umum, dikurangi refund periode ini. Sama dengan 'Penjualan Bersih' di Laba-Rugi.">Total Penjualan</x-metric-label>
                @php $c = $change($currentNet, $previousNet); @endphp
                @if ($c)
                    <span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $c['up'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                        @if ($c['up']) <x-heroicon-m-arrow-up class="h-3 w-3" /> @else <x-heroicon-m-arrow-down class="h-3 w-3" /> @endif
                        {{ $c['value'] }}%
                    </span>
                @endif
                <a href="{{ $drillUrl }}" class="mt-1 block text-3xl font-bold tabular-nums hover:text-primary-600 hover:underline dark:hover:text-primary-400">{{ $rupiah($currentNet) }}</a>
                @if ($current['refund'] > 0)
                    <div class="mt-1 text-xs text-danger-600 dark:text-danger-400">setelah pengembalian {{ $rupiah($current['refund']) }} (kotor {{ $rupiah($current['revenue']) }})</div>
                @endif
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Akumulasi dari Awal Bulan {{ $rupiah($result['monthToDateRevenue']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Proyeksi Bulan Ini {{ $rupiah($result['projection']) }}</div>
            </div>

            <div>
                <x-metric-label tooltip="transaction_amount − amount_received untuk booking periode ini. amount_received kosong dianggap lunas penuh.">Penjualan Belum Dibayar (Piutang)</x-metric-label>
                <a href="{{ $drillUrl }}" class="mt-1 block text-2xl font-bold tabular-nums hover:underline {{ $current['outstanding'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $rupiah($current['outstanding']) }}</a>
                <div class="mt-3">
                    <x-metric-label tooltip="amount_received yang sudah diterima dari booking periode ini (transaction_amount penuh kalau kolom kosong).">Penjualan Terbayar</x-metric-label>
                </div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($current['received']) }}</div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-metric-label tooltip="Jumlah booking yang tercatat sebagai pendapatan periode ini (lunas maupun masih piutang).">Transaksi</x-metric-label>
                    @php $c = $change($current['count'], $previous['count']); @endphp
                    @if ($c)<span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $c['up'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">@if ($c['up'])<x-heroicon-m-arrow-up class="h-3 w-3" />@else<x-heroicon-m-arrow-down class="h-3 w-3" />@endif{{ $c['value'] }}%</span>@endif
                    <a href="{{ $drillUrl }}" class="block text-lg font-bold tabular-nums hover:text-primary-600 hover:underline dark:hover:text-primary-400">{{ number_format($current['count'], 0, ',', '.') }}</a>
                </div>
                <div>
                    <x-metric-label tooltip="Total Penjualan dibagi Transaksi — rata-rata nilai per booking periode ini.">Penjualan per Transaksi</x-metric-label>
                    @php $c = $change($currentAvg, $previousAvg); @endphp
                    @if ($c)<span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $c['up'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">@if ($c['up'])<x-heroicon-m-arrow-up class="h-3 w-3" />@else<x-heroicon-m-arrow-down class="h-3 w-3" />@endif{{ $c['value'] }}%</span>@endif
                    <div class="text-lg font-bold tabular-nums">{{ $rupiah($currentAvg) }}</div>
                </div>
                <div>
                    <x-metric-label tooltip="Jumlah jenis produk/jasa periode ini. Satu booking Kaca Film + PPF + Detailing dihitung 3.">Produk Terjual</x-metric-label>
                    @php $c = $change($current['productsSold'], $previous['productsSold']); @endphp
                    @if ($c)<span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $c['up'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">@if ($c['up'])<x-heroicon-m-arrow-up class="h-3 w-3" />@else<x-heroicon-m-arrow-down class="h-3 w-3" />@endif{{ $c['value'] }}%</span>@endif
                    <div class="text-lg font-bold tabular-nums">{{ number_format($current['productsSold'], 0, ',', '.') }}</div>
                </div>
                <div>
                    <x-metric-label tooltip="Produk Terjual dibagi Transaksi — rata-rata jumlah produk per booking periode ini.">Produk per Transaksi</x-metric-label>
                    @php $c = $change($currentProductAvg, $previousProductAvg); @endphp
                    @if ($c)<span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $c['up'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">@if ($c['up'])<x-heroicon-m-arrow-up class="h-3 w-3" />@else<x-heroicon-m-arrow-down class="h-3 w-3" />@endif{{ $c['value'] }}%</span>@endif
                    <div class="text-lg font-bold tabular-nums">{{ number_format($currentProductAvg, 2, ',', '.') }}</div>
                </div>
            </div>
        </div>

        @if ($result['growthHasComparison'])
            @php
                $up = $result['growthDelta'] >= 0;
            @endphp
            <div @class([
                'mt-4 flex items-center gap-2 rounded-lg px-3 py-2 text-sm',
                'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $up,
                'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' => ! $up,
            ])>
                @if ($up)
                    <x-heroicon-o-arrow-trending-up class="h-4 w-4 flex-shrink-0" />
                @else
                    <x-heroicon-o-arrow-trending-down class="h-4 w-4 flex-shrink-0" />
                @endif
                <span>
                    Penjualan bulan ini {{ $up ? 'meningkat' : 'menurun' }} senilai
                    <strong class="font-semibold">{{ $rupiah(abs($result['growthDelta'])) }}</strong>
                    dibanding periode yang sama bulan lalu.
                </span>
            </div>
        @endif

        <details class="mt-4 rounded-lg border border-gray-200 text-sm dark:border-white/10">
            <summary class="cursor-pointer px-3 py-2 font-medium text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                Cara baca angka
            </summary>
            <div class="space-y-2 border-t border-gray-200 px-3 py-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                <p><strong class="text-gray-700 dark:text-gray-200">Total Penjualan</strong> — jumlah <code>transaction_amount</code> dari booking yang sudah tercatat ke Jurnal Umum (sudah lewat "Proses Referral"), dikurangi nominal refund yang diproses pada periode ini. Sama definisi dengan "Penjualan Bersih" di Ringkasan Penjualan &amp; Laba-Rugi. Booking selesai yang belum diproses tidak ikut; booking dibatalkan tidak mungkin masuk (tidak punya jurnal).</p>
                <p><strong class="text-gray-700 dark:text-gray-200">Piutang</strong> — <code>transaction_amount − amount_received</code>. Kolom <code>amount_received</code> yang kosong dianggap lunas penuh, jadi hanya booking yang eksplisit belum lunas yang masuk sini.</p>
                <p><strong class="text-gray-700 dark:text-gray-200">Penjualan Terbayar</strong> — <code>amount_received</code> yang sudah diterima (atau <code>transaction_amount</code> penuh kalau kolom kosong).</p>
                <p><strong class="text-gray-700 dark:text-gray-200">Transaksi</strong> — jumlah booking yang tercatat sebagai pendapatan periode ini, lunas maupun masih piutang.</p>
                <p><strong class="text-gray-700 dark:text-gray-200">Produk Terjual</strong> — jumlah jenis produk/jasa yang tercakup. Satu booking dengan Kaca Film + PPF + Detailing sekaligus dihitung 3, sesuai flag <code>product_kaca_film</code> / <code>product_ppf</code> / <code>product_detailing</code>.</p>
                <p><strong class="text-gray-700 dark:text-gray-200">Akumulasi dari Awal Bulan</strong> &amp; <strong class="text-gray-700 dark:text-gray-200">Proyeksi Bulan Ini</strong> — selalu dihitung dari bulan kalender berjalan, tidak mengikuti filter periode di atas.</p>
                <p>Badge <x-heroicon-m-arrow-up class="inline h-3 w-3" /> / <x-heroicon-m-arrow-down class="inline h-3 w-3" /> membandingkan periode ini dengan periode sebelumnya yang sama panjang. Kalau periode pembanding nol, badge tidak ditampilkan.</p>
            </div>
        </details>
        @endif
    </x-filament::section>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Grafik di bawah selalu menampilkan bulan kalender berjalan (perbandingan dengan bulan lalu) —
        tidak mengikuti filter periode, tanggal, atau cabang di atas.
    </p>

    <x-filament-widgets::widgets
        :widgets="[\App\Filament\Widgets\BookingRevenueTrendChart::class, \App\Filament\Widgets\BookingRevenueByCategoryChart::class]"
        :columns="1"
    />

    {{--
        "Stok Terendah" (diminta 2026-09-09) -- BUKAN widget baru, pakai
        LANGSUNG widget yang sudah ada di Dashboard Inventaris (satu
        sumber kebenaran, bukan duplikat query). Masing-masing widget
        sudah punya canView() sendiri (cek akses menu Bahan Baku/Barang
        Habis Pakai) jadi aman ditambahkan di sini tanpa guard tambahan
        -- staff yang tidak punya akses inventaris otomatis tidak lihat
        widget ini sama sekali.
    --}}
    <x-filament-widgets::widgets
        :widgets="[\App\Filament\InventoryWidgets\MaterialsNeedingAttentionWidget::class, \App\Filament\InventoryWidgets\ConsumablesNeedingAttentionWidget::class]"
        :columns="1"
    />
</x-filament-panels::page>
