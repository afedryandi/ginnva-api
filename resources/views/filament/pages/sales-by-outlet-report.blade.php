<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini (banner, warna angka, jarak sel tabel). Grid kartu memakai style inline di elemennya. --}}
    @once
        <style>
            .so-num { font-variant-numeric: tabular-nums; text-align: right; }
            .so-up { color: #15803d; } .so-down { color: #b91c1c; }
            .dark .so-up { color: #4ade80; } .dark .so-down { color: #f87171; }
            .so-banner { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid #fcd34d; border-radius: 8px; background: #fffbeb; color: #92400e; font-size: 14px; text-decoration: none; }
            .so-banner:hover { background: #fef3c7; }
            .so-banner svg { flex-shrink: 0; }
            .dark .so-banner { background: rgba(245, 158, 11, .1); border-color: rgba(245, 158, 11, .3); color: #fcd34d; }
            .dark .so-banner:hover { background: rgba(245, 158, 11, .2); }
            .so-table th, .so-table td { padding: .5rem .75rem; white-space: nowrap; }
            .so-table th:first-child, .so-table td:first-child { padding-left: 0; }
            .so-table tfoot td { font-weight: 700; border-top: 2px solid rgba(107, 114, 128, .5); }
        </style>
    @endonce

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $persen = fn ($n) => number_format($n, 1, ',', '.') . '%';
        $filterTargets = 'data.from, data.to, data.preset';
        // Piutang hanya ada bila modul Keuangan menyala: saat mati, piutang dicatat di Accurate (keputusan pemilik 2026-11-04).
        $financeOn = (bool) config('features.finance');
    @endphp

    @if ($result['pendingCount'] > 0)
        <a href="{{ \App\Filament\Resources\BookingResource::getUrl('index', ['tableFilters' => ['selesai_belum_diproses' => ['isActive' => true]]]) }}" class="so-banner">
            <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
            <span>
                <strong class="font-semibold">{{ number_format($result['pendingCount'], 0, ',', '.') }} booking</strong>
                sudah selesai tapi belum diproses ke pendapatan — nominalnya belum masuk laporan ini.
            </span>
            <x-heroicon-o-arrow-right class="ml-auto h-4 w-4" />
        </a>
    @endif

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan Semua Outlet (bersih)</div>
            <div class="mt-1 text-2xl font-bold so-num so-up" style="text-align: left">{{ $rupiah($result['totalRevenue']) }}</div>
            @if ($result['totalRefund'] > 0)
                <div class="mt-1 text-xs so-down">setelah pengembalian {{ $rupiah($result['totalRefund']) }} (kotor {{ $rupiah($result['totalRevenue'] + $result['totalRefund']) }})</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Transaksi</div>
            <div class="mt-1 text-2xl font-bold so-num" style="text-align: left">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Produk</div>
            <div class="mt-1 text-2xl font-bold so-num" style="text-align: left">{{ number_format($result['totalProducts'], 0, ',', '.') }}</div>
        </x-filament::section>
    </div>

    {{-- @livewire() langsung (bukan <x-filament-widgets::widgets>) supaya bisa kirim from/to ke mount() grafik. --}}
    @livewire(
        \App\Filament\Widgets\SalesByOutletChart::class,
        ['from' => $result['from']->toDateString(), 'to' => $result['to']->toDateString()],
        key('sales-by-outlet-chart-' . $result['from']->toDateString() . '-' . $result['to']->toDateString())
    )

    <x-filament::section>
        <x-slot name="heading">Rekap per Outlet</x-slot>
        <x-slot name="description">
            Diurutkan dari penjualan tertinggi. Penjualan (bersih) sudah dikurangi pengembalian yang diproses pada periode ini; grafik di atas
            menampilkan nilai sebelum pengembalian. Outlet nonaktif hanya tampil bila punya transaksi pada rentang ini.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm so-table">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th>Outlet</th>
                        <th class="so-num">Transaksi</th>
                        <th class="so-num">Penjualan (bersih)</th>
                        <th class="so-num">Penjualan %</th>
                        <th class="so-num">Pengembalian</th>
                        <th class="so-num">Produk</th>
                        <th class="so-num">Produk %</th>
                        <th class="so-num">Rata-rata/Transaksi</th>
                        <th class="so-num">Produk/Transaksi</th>
                        @if ($financeOn)
                            <th class="so-num">Piutang</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['count'] === 0 ? 'text-gray-400 dark:text-gray-500' : '' }}">
                            <td class="font-medium">
                                @if ($row['count'] > 0)
                                    <a href="{{ $this->salesUrl($row['store']->id) }}" class="hover:underline" title="Lihat Detail Penjualan outlet ini">{{ $row['store']->name }}</a>
                                @else
                                    {{ $row['store']->name }}
                                @endif
                            </td>
                            <td class="so-num">{{ $row['count'] }}</td>
                            <td class="so-num">{{ $rupiah($row['revenue']) }}</td>
                            <td class="so-num">{{ $persen($row['revenuePct']) }}</td>
                            <td class="so-num {{ $row['refund'] > 0 ? 'so-down' : '' }}">{{ $row['refund'] > 0 ? '(' . $rupiah($row['refund']) . ')' : '—' }}</td>
                            <td class="so-num">{{ $row['products'] }}</td>
                            <td class="so-num">{{ $persen($row['productsPct']) }}</td>
                            <td class="so-num">{{ $rupiah($row['avg']) }}</td>
                            <td class="so-num">{{ number_format($row['productsPerTransaction'], 2) }}</td>
                            @if ($financeOn)
                                <td class="so-num {{ $row['outstanding'] > 0 ? 'so-down' : '' }}">{{ $row['outstanding'] > 0 ? $rupiah($row['outstanding']) : '—' }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $financeOn ? 10 : 9 }}" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada outlet yang bisa diakses.</td></tr>
                    @endforelse
                </tbody>
                @if (count($result['rows']) > 0)
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="so-num">{{ number_format($result['totalCount'], 0, ',', '.') }}</td>
                            <td class="so-num">{{ $rupiah($result['totalRevenue']) }}</td>
                            <td class="so-num">{{ $result['totalRevenue'] > 0 ? $persen(100) : '—' }}</td>
                            <td class="so-num">{{ $result['totalRefund'] > 0 ? '(' . $rupiah($result['totalRefund']) . ')' : '—' }}</td>
                            <td class="so-num">{{ number_format($result['totalProducts'], 0, ',', '.') }}</td>
                            <td class="so-num">{{ $result['totalProducts'] > 0 ? $persen(100) : '—' }}</td>
                            <td class="so-num">{{ $result['totalCount'] > 0 ? $rupiah($result['totalRevenue'] / $result['totalCount']) : '—' }}</td>
                            <td class="so-num">{{ $result['totalCount'] > 0 ? number_format($result['totalProducts'] / $result['totalCount'], 2) : '—' }}</td>
                            @if ($financeOn)
                                <td class="so-num">{{ $result['totalOutstanding'] > 0 ? $rupiah($result['totalOutstanding']) : '—' }}</td>
                            @endif
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
