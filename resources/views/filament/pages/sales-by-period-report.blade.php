<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini (banner, warna angka, jarak sel tabel). Grid kartu memakai style inline di elemennya. --}}
    @once
        <style>
            .sp-num { font-variant-numeric: tabular-nums; text-align: right; }
            .sp-up { color: #15803d; } .sp-down { color: #b91c1c; }
            .dark .sp-up { color: #4ade80; } .dark .sp-down { color: #f87171; }
            .sp-banner { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid #fcd34d; border-radius: 8px; background: #fffbeb; color: #92400e; font-size: 14px; text-decoration: none; }
            .sp-banner:hover { background: #fef3c7; }
            .sp-banner svg { flex-shrink: 0; }
            .dark .sp-banner { background: rgba(245, 158, 11, .1); border-color: rgba(245, 158, 11, .3); color: #fcd34d; }
            .dark .sp-banner:hover { background: rgba(245, 158, 11, .2); }
            .sp-table th, .sp-table td { padding: .5rem .75rem; white-space: nowrap; }
            .sp-table th:first-child, .sp-table td:first-child { padding-left: 0; }
            .sp-table tfoot td { font-weight: 700; border-top: 2px solid rgba(107, 114, 128, .5); }
        </style>
    @endonce

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $filterTargets = 'data.from, data.to, data.granularity, data.store_id, data.preset, data.hide_empty';
        // Diterima dan Piutang hanya ada bila modul Keuangan menyala: saat mati, piutang dicatat di Accurate (keputusan pemilik 2026-11-04).
        $financeOn = (bool) config('features.finance');
    @endphp

    @if ($result['pendingCount'] > 0)
        <a href="{{ \App\Filament\Resources\BookingResource::getUrl('index', ['tableFilters' => ['selesai_belum_diproses' => ['isActive' => true]]]) }}" class="sp-banner">
            <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
            <span>
                <strong class="font-semibold">{{ number_format($result['pendingCount'], 0, ',', '.') }} booking</strong>
                sudah selesai tapi belum diproses ke pendapatan — nominalnya belum masuk laporan ini.
            </span>
            <x-heroicon-o-arrow-right class="ml-auto h-4 w-4" />
        </a>
    @endif

    @if ($result['rangeClamped'])
        <div class="sp-banner">
            <x-heroicon-o-information-circle class="h-4 w-4" />
            <span>
                Rentang tanggal yang dipilih terlalu panjang — laporan ini dibatasi maksimal 2 tahun terakhir
                ({{ $result['from']->translatedFormat('d M Y') }} – {{ $result['to']->translatedFormat('d M Y') }})
                supaya tidak membebani server. Persempit rentang tanggal untuk hasil yang lebih presisi.
            </span>
        </div>
    @endif

    @if ($result['tooManyBuckets'])
        <div class="sp-banner">
            <x-heroicon-o-information-circle class="h-4 w-4" />
            <span>
                Tabel ini {{ number_format(count($result['rows']), 0, ',', '.') }} baris — rentang tanggal cukup panjang untuk granularitas
                "{{ ucfirst($result['granularity']) }}". Pertimbangkan granularitas lebih kasar (Mingguan/Bulanan), aktifkan "Sembunyikan periode
                tanpa transaksi", atau persempit rentang tanggal supaya lebih mudah dibaca.
            </span>
        </div>
    @endif

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan (Seluruh Rentang)</div>
            <div class="mt-1 text-2xl font-bold sp-num sp-up" style="text-align: left">{{ $rupiah($result['totalRevenue']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Transaksi</div>
            <div class="mt-1 text-2xl font-bold sp-num" style="text-align: left">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Produk</div>
            <div class="mt-1 text-2xl font-bold sp-num" style="text-align: left">{{ number_format($result['totalProducts'], 0, ',', '.') }}</div>
        </x-filament::section>
    </div>

    {{-- @livewire() langsung (bukan <x-filament-widgets::widgets>) supaya bisa kirim from/to/granularity/storeId ke mount() grafik. wire:key
         ikut semua parameter supaya widget REMOUNT (bukan cuma re-render) begitu salah satu berubah. --}}
    @livewire(
        \App\Filament\ReportWidgets\SalesByPeriodChart::class,
        ['from' => $result['from']->toDateString(), 'to' => $result['to']->toDateString(), 'granularity' => $result['granularity'], 'storeId' => $result['storeId']],
        key('sales-by-period-chart-' . $result['from']->toDateString() . '-' . $result['to']->toDateString() . '-' . $result['granularity'] . '-' . ($result['storeId'] ?? 'all'))
    )

    <x-filament::section>
        <x-slot name="heading">Rekap per Periode</x-slot>
        <x-slot name="description">
            Periode tanpa transaksi di dalam rentang tetap ditampilkan (Rp 0) supaya tren yang sepi kelihatan jelas; hari yang belum terjadi tidak dibuatkan
            baris. Pengembalian dikelompokkan menurut tanggal refund diproses, bukan tanggal penjualannya. Klik nama metrik di legend grafik untuk
            sembunyikan/tampilkan garisnya.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm sp-table">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th>Periode</th>
                        <th class="sp-num">Transaksi</th>
                        <th class="sp-num">Penjualan</th>
                        @if ($financeOn)
                            <th class="sp-num">Diterima</th>
                            <th class="sp-num">Piutang</th>
                        @endif
                        <th class="sp-num">Produk</th>
                        <th class="sp-num">Pengembalian</th>
                        <th class="sp-num">Komisi</th>
                        <th class="sp-num">Penjualan/Transaksi</th>
                        <th class="sp-num">Produk/Transaksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['count'] === 0 ? 'text-gray-400 dark:text-gray-500' : '' }}">
                            <td class="font-medium">
                                @if ($row['count'] > 0)
                                    <a href="{{ $this->salesUrl($row['bucketFrom'], $row['bucketTo']) }}" class="hover:underline" title="Lihat Detail Penjualan periode ini">{{ $row['label'] }}</a>
                                @else
                                    {{ $row['label'] }}
                                @endif
                            </td>
                            <td class="sp-num">{{ $row['count'] }}</td>
                            <td class="sp-num">{{ $rupiah($row['revenue']) }}</td>
                            @if ($financeOn)
                                <td class="sp-num">{{ $rupiah($row['received']) }}</td>
                                <td class="sp-num {{ $row['outstanding'] > 0 ? 'sp-down' : '' }}">{{ $row['outstanding'] > 0 ? $rupiah($row['outstanding']) : '—' }}</td>
                            @endif
                            <td class="sp-num">{{ $row['products'] }}</td>
                            <td class="sp-num {{ $row['refund'] > 0 ? 'sp-down' : '' }}">{{ $row['refund'] > 0 ? '(' . $rupiah($row['refund']) . ')' : '—' }}</td>
                            <td class="sp-num">
                                {{ $row['commission'] > 0 ? $rupiah($row['commission']) : '—' }}
                                @if ($row['hasUnratedJob'])
                                    <span title="Ada installer yang mengerjakan booking di periode ini tapi komisinya belum diatur — nominal di atas belum lengkap.">*</span>
                                @endif
                            </td>
                            <td class="sp-num">{{ $row['count'] > 0 ? $rupiah($row['revenue'] / $row['count']) : '—' }}</td>
                            <td class="sp-num">{{ $row['count'] > 0 ? number_format($row['products'] / $row['count'], 2) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $financeOn ? 10 : 8 }}" class="py-4 text-center text-gray-500 dark:text-gray-400">{{ $result['hideEmpty'] ? 'Tidak ada transaksi pada rentang ini.' : 'Pilih rentang tanggal di atas.' }}</td></tr>
                    @endforelse
                </tbody>
                @if (count($result['rows']) > 0)
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="sp-num">{{ number_format($result['totalCount'], 0, ',', '.') }}</td>
                            <td class="sp-num">{{ $rupiah($result['totalRevenue']) }}</td>
                            @if ($financeOn)
                                <td class="sp-num">{{ $rupiah($result['totalReceived']) }}</td>
                                <td class="sp-num">{{ $result['totalOutstanding'] > 0 ? $rupiah($result['totalOutstanding']) : '—' }}</td>
                            @endif
                            <td class="sp-num">{{ number_format($result['totalProducts'], 0, ',', '.') }}</td>
                            <td class="sp-num">{{ $result['totalRefund'] > 0 ? '(' . $rupiah($result['totalRefund']) . ')' : '—' }}</td>
                            <td class="sp-num">{{ $result['totalCommission'] > 0 ? $rupiah($result['totalCommission']) : '—' }}</td>
                            <td class="sp-num">{{ $result['totalCount'] > 0 ? $rupiah($result['totalRevenue'] / $result['totalCount']) : '—' }}</td>
                            <td class="sp-num">{{ $result['totalCount'] > 0 ? number_format($result['totalProducts'] / $result['totalCount'], 2) : '—' }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
            * = ada installer yang komisinya belum diatur (menu Installer) pada periode itu — nominal Komisi belum mencerminkan semua pekerjaan.
        </p>
    </x-filament::section>
    </div>
</x-filament-panels::page>
