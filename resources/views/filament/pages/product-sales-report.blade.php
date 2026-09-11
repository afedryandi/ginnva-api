<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $filterTargets = 'data.from, data.to';
    @endphp

    @if ($result['unassignedCount'] > 0)
        <div class="rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300">
            {{ $result['unassignedCount'] }} dari {{ $result['totalCount'] }} transaksi ({{ number_format($result['unassignedPct'], 1) }}%) belum diisi varian produk (SKU) spesifiknya —
            laporan ini baru akurat sepenuhnya kalau staff konsisten mengisi field "Varian Produk (SKU)" saat input/edit booking.
        </div>
    @endif

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan Produk (bersih)</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($result['totalRevenue']) }}</div>
            @if ($result['totalRefundAmount'] > 0)
                <div class="mt-1 text-xs text-danger-600 dark:text-danger-400">setelah pengembalian {{ $rupiah($result['totalRefundAmount']) }} (kotor {{ $rupiah($result['grossRevenue']) }})</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Produk Terjual</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Refund</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ $rupiah($result['totalRefundAmount']) }}</div>
        </x-filament::section>
    </div>

    {{--
        @livewire() langsung (bukan <x-filament-widgets::widgets>) supaya
        bisa kirim from/to/storeId ke mount() grafik (audit 2026-09-11,
        temuan A) — sebelumnya grafik selalu 14/30/90 hari terakhir sendiri.
    --}}
    @livewire(
        \App\Filament\Widgets\ProductSalesChart::class,
        ['from' => $result['from']->toDateString(), 'to' => $result['to']->toDateString(), 'storeId' => $result['storeId']],
        key('product-sales-chart-' . $result['from']->toDateString() . '-' . $result['to']->toDateString() . '-' . ($result['storeId'] ?? 'all'))
    )

    <x-filament::section>
        <x-slot name="heading">Penjualan per Produk (SKU)</x-slot>
        <x-slot name="description">
            Diurutkan dari penjualan tertinggi. "Departemen"/"Kategori" ala Majoo tidak ditampilkan —
            katalog Ginnva tidak pakai struktur itu (SKU langsung di bawah Jenis Produk PPF/Kaca Film).
            Laba Kotor/HPP tidak ditampilkan — masih blocked (butuh HPP, sama seperti laporan Penjualan lain).
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Produk</th>
                        <th class="py-2 pr-3">SKU</th>
                        <th class="py-2 pr-3">Jenis Produk</th>
                        <th class="py-2 pr-3 text-right">Jumlah</th>
                        <th class="py-2 pr-3 text-right">Jumlah %</th>
                        <th class="py-2 pr-3 text-right">Penjualan</th>
                        <th class="py-2 pr-3 text-right">Penjualan %</th>
                        <th class="py-2 pr-3 text-right">Jumlah Refund</th>
                        <th class="py-2 pl-3 text-right">Refund</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['product'] === null ? 'italic text-gray-400 dark:text-gray-500' : '' }}">
                            <td class="py-2 pr-3 font-medium">{{ $row['name'] }}</td>
                            <td class="py-2 pr-3 font-mono">{{ $row['sku'] }}</td>
                            <td class="py-2 pr-3">{{ $row['type'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['count'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['countPct'], 1) }}%</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $rupiah($row['revenue']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['revenuePct'], 1) }}%</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['refundCount'] > 0 ? $row['refundCount'] : '—' }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums {{ $row['refundAmount'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $row['refundAmount'] > 0 ? '(' . $rupiah($row['refundAmount']) . ')' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-4 text-center text-gray-500 dark:text-gray-400">Belum ada data pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
