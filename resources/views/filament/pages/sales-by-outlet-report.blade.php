<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $persen = fn ($n) => number_format($n, 1, ',', '.') . '%';
        $filterTargets = 'data.from, data.to';
    @endphp

    @if ($result['pendingCount'] > 0)
        <a
            href="{{ \App\Filament\Resources\BookingResource::getUrl('index', ['tableFilters' => ['selesai_belum_diproses' => ['isActive' => true]]]) }}"
            class="flex items-center gap-2 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-800 transition hover:bg-warning-100 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300 dark:hover:bg-warning-500/20"
        >
            <x-heroicon-o-exclamation-triangle class="h-4 w-4 flex-shrink-0" />
            <span>
                <strong class="font-semibold">{{ number_format($result['pendingCount'], 0, ',', '.') }} booking</strong>
                sudah selesai tapi belum diproses ke pendapatan — nominalnya belum masuk laporan ini.
            </span>
            <x-heroicon-o-arrow-right class="ml-auto h-4 w-4 flex-shrink-0" />
        </a>
    @endif

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan Semua Outlet (bersih)</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($result['totalRevenue']) }}</div>
            @if ($result['totalRefund'] > 0)
                <div class="mt-1 text-xs text-danger-600 dark:text-danger-400">setelah pengembalian {{ $rupiah($result['totalRefund']) }} (kotor {{ $rupiah($result['totalRevenue'] + $result['totalRefund']) }})</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Transaksi</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Produk</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalProducts'], 0, ',', '.') }}</div>
        </x-filament::section>
    </div>

    {{--
        @livewire() langsung (bukan <x-filament-widgets::widgets>) supaya
        bisa kirim from/to ke mount() grafik (audit 2026-09-11, temuan A)
        — SEBELUMNYA grafik selalu 14/30/90 hari terakhir sendiri.
    --}}
    @livewire(
        \App\Filament\Widgets\SalesByOutletChart::class,
        ['from' => $result['from']->toDateString(), 'to' => $result['to']->toDateString()],
        key('sales-by-outlet-chart-' . $result['from']->toDateString() . '-' . $result['to']->toDateString())
    )

    <x-filament::section>
        <x-slot name="heading">Rekap per Outlet</x-slot>
        <x-slot name="description">
            Diurutkan dari penjualan tertinggi. "Laba Kotor" tidak ditampilkan — butuh HPP yang belum tersedia
            (lihat Ringkasan Penjualan untuk detailnya).
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Outlet</th>
                        <th class="py-2 pr-3 text-right">Transaksi</th>
                        <th class="py-2 pr-3 text-right">Penjualan (bersih)</th>
                        <th class="py-2 pr-3 text-right">Penjualan %</th>
                        <th class="py-2 pr-3 text-right">Pengembalian</th>
                        <th class="py-2 pr-3 text-right">Produk</th>
                        <th class="py-2 pr-3 text-right">Produk %</th>
                        <th class="py-2 pr-3 text-right">Rata-rata/Transaksi</th>
                        <th class="py-2 pr-3 text-right">Produk/Transaksi</th>
                        <th class="py-2 pl-3 text-right">Piutang</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['count'] === 0 ? 'text-gray-400 dark:text-gray-500' : '' }}">
                            <td class="py-2 pr-3 font-medium">{{ $row['store']->name }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['count'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $rupiah($row['revenue']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $persen($row['revenuePct']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums {{ $row['refund'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $row['refund'] > 0 ? '(' . $rupiah($row['refund']) . ')' : '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['products'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $persen($row['productsPct']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $rupiah($row['avg']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['productsPerTransaction'], 2) }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums {{ $row['outstanding'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $row['outstanding'] > 0 ? $rupiah($row['outstanding']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada outlet yang bisa diakses.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
