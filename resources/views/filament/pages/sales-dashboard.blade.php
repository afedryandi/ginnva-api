<x-filament-panels::page>
    @php
        $result = $this->getResult();
        $current = $result['current'];
        $previous = $result['previous'];
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $avgOf = fn ($total, $count) => $count > 0 ? $total / $count : 0;

        $currentAvg = $avgOf($current['revenue'], $current['count']);
        $previousAvg = $avgOf($previous['revenue'], $previous['count']);
        $currentProductAvg = $avgOf($current['productsSold'], $current['count']);
        $previousProductAvg = $avgOf($previous['productsSold'], $previous['count']);

        // Badge %perubahan gaya Majoo ("↓92.88%") — periode pembanding 0
        // dianggap tidak ada pembanding (bukan "naik tak terhingga%").
        $change = function ($curr, $prev) {
            if ($prev <= 0) {
                return null;
            }
            $percent = (($curr - $prev) / $prev) * 100;
            return ['arrow' => $percent >= 0 ? '↑' : '↓', 'value' => number_format(abs($percent), 2, ',', '.'), 'up' => $percent >= 0];
        };
    @endphp

    {{-- Toggle periode + navigasi tanggal — persis pola Majoo --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-lg border border-gray-200 p-1 dark:border-white/10">
            @foreach (['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulan'] as $key => $label)
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
    </div>

    <x-filament::section>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            <div>
                <x-metric-label tooltip="Total Penjualan = jumlah transaction_amount dari booking yang SUDAH tercatat ke Jurnal Umum (sudah lewat 'Proses Referral'). Booking berstatus Selesai yang BELUM diisi nominal transaksi tidak ikut terhitung. Booking dengan 2 produk (Kaca Film + PPF) sekaligus dibagi rata 50/50 ke breakdown kategori — sama logika dengan jurnal Pendapatan di Keuangan. Nilai belum dikurangi booking yang dibatalkan/refund.">Total Penjualan</x-metric-label>
                @php $c = $change($current['revenue'], $previous['revenue']); @endphp
                @if ($c)
                    <span class="text-xs font-semibold {{ $c['up'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $c['arrow'] }}{{ $c['value'] }}%</span>
                @endif
                <div class="mt-1 text-3xl font-bold tabular-nums">{{ $rupiah($current['revenue']) }}</div>
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Akumulasi dari Awal Bulan {{ $rupiah($result['monthToDateRevenue']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Proyeksi Bulan Ini {{ $rupiah($result['projection']) }}</div>
            </div>

            <div>
                <x-metric-label tooltip="Penjualan Belum Dibayar (Piutang) = transaction_amount dikurangi amount_received untuk booking periode ini. amount_received kosong dianggap LUNAS PENUH (bukan piutang) — cuma booking yang eksplisit belum lunas semua yang masuk sini.">Penjualan Belum Dibayar (Piutang)</x-metric-label>
                <div class="mt-1 text-2xl font-bold tabular-nums {{ $current['outstanding'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $rupiah($current['outstanding']) }}</div>
                <div class="mt-3">
                    <x-metric-label tooltip="Penjualan Terbayar = amount_received (atau transaction_amount penuh kalau kolom ini kosong, dianggap lunas) yang sudah diterima dari booking periode ini.">Penjualan Terbayar</x-metric-label>
                </div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($current['received']) }}</div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-metric-label tooltip="Transaksi = jumlah booking yang tercatat sebagai pendapatan pada periode ini (sudah lewat 'Proses Referral'), baik sudah lunas penuh maupun masih ada piutang.">Transaksi</x-metric-label>
                    @php $c = $change($current['count'], $previous['count']); @endphp
                    @if ($c)<span class="text-xs font-semibold {{ $c['up'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $c['arrow'] }}{{ $c['value'] }}%</span>@endif
                    <div class="text-lg font-bold tabular-nums">{{ number_format($current['count'], 0, ',', '.') }}</div>
                </div>
                <div>
                    <x-metric-label tooltip="Penjualan per Transaksi = Total Penjualan dibagi Transaksi — rata-rata nilai per booking pada periode ini.">Penjualan per Transaksi</x-metric-label>
                    @php $c = $change($currentAvg, $previousAvg); @endphp
                    @if ($c)<span class="text-xs font-semibold {{ $c['up'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $c['arrow'] }}{{ $c['value'] }}%</span>@endif
                    <div class="text-lg font-bold tabular-nums">{{ $rupiah($currentAvg) }}</div>
                </div>
                <div>
                    <x-metric-label tooltip="Produk Terjual = jumlah produk yang terpasang pada periode ini. Booking dengan Kaca Film + PPF sekaligus dihitung 2 (bukan 1), sesuai flag product_kaca_film/product_ppf pada booking.">Produk Terjual</x-metric-label>
                    @php $c = $change($current['productsSold'], $previous['productsSold']); @endphp
                    @if ($c)<span class="text-xs font-semibold {{ $c['up'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $c['arrow'] }}{{ $c['value'] }}%</span>@endif
                    <div class="text-lg font-bold tabular-nums">{{ number_format($current['productsSold'], 0, ',', '.') }}</div>
                </div>
                <div>
                    <x-metric-label tooltip="Produk per Transaksi = Produk Terjual dibagi Transaksi — rata-rata jumlah produk (Kaca Film/PPF) per booking pada periode ini.">Produk per Transaksi</x-metric-label>
                    @php $c = $change($currentProductAvg, $previousProductAvg); @endphp
                    @if ($c)<span class="text-xs font-semibold {{ $c['up'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $c['arrow'] }}{{ $c['value'] }}%</span>@endif
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
                    Penjualanmu bulan ini {{ $up ? 'meningkat' : 'menurun' }} senilai
                    <strong class="font-semibold">{{ $rupiah(abs($result['growthDelta'])) }}</strong>
                    dibanding periode yang sama bulan lalu.
                </span>
            </div>
        @endif
    </x-filament::section>

    <x-filament-widgets::widgets
        :widgets="[\App\Filament\Widgets\BookingRevenueTrendChart::class, \App\Filament\Widgets\BookingRevenueByCategoryChart::class]"
        :columns="1"
    />
</x-filament-panels::page>
