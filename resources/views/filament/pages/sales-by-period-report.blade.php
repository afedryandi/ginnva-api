<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan (Seluruh Rentang)</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($result['totalRevenue']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Transaksi</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Rekap per Periode</x-slot>
        <x-slot name="description">Periode tanpa transaksi tetap ditampilkan (Rp 0) supaya tren yang sepi kelihatan jelas, bukan hilang dari tabel.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Periode</th>
                        <th class="py-2 pr-3 text-right">Transaksi</th>
                        <th class="py-2 pr-3 text-right">Penjualan</th>
                        <th class="py-2 pr-3 text-right">Diterima</th>
                        <th class="py-2 pl-3 text-right">Piutang</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['count'] === 0 ? 'text-gray-400 dark:text-gray-500' : '' }}">
                            <td class="py-2 pr-3 font-medium">{{ $row['label'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['count'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $rupiah($row['revenue']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $rupiah($row['received']) }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums {{ $row['outstanding'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $row['outstanding'] > 0 ? $rupiah($row['outstanding']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-gray-500 dark:text-gray-400">Pilih rentang tanggal di atas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
