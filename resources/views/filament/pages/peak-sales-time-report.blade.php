<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($result['totalRevenue']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Transaksi</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Produk</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalProducts'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Pelanggan</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalCustomers'], 0, ',', '.') }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Waktu Teramai per Hari</x-slot>
        <x-slot name="description">"Pelanggan" dihitung pelanggan UNIK per hari-dalam-seminggu (bukan per tanggal kalender) — pelanggan yang sama datang di 2 hari Senin berbeda minggu tetap dihitung 1x.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Waktu</th>
                        <th class="py-2 pr-3 text-right">Penjualan (Rp)</th>
                        <th class="py-2 pr-3 text-right">Penjualan (%)</th>
                        <th class="py-2 pr-3 text-right">Transaksi</th>
                        <th class="py-2 pr-3 text-right">Transaksi (%)</th>
                        <th class="py-2 pr-3 text-right">Produk</th>
                        <th class="py-2 pr-3 text-right">Produk (%)</th>
                        <th class="py-2 pl-3 text-right">Pelanggan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['count'] === 0 ? 'text-gray-400 dark:text-gray-500' : '' }}">
                            <td class="py-2 pr-3 font-medium">{{ $row['dayName'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $rupiah($row['revenue']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['revenuePct'], 1) }}%</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['count'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['countPct'], 1) }}%</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['products'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['productsPct'], 1) }}%</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $row['customers'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
