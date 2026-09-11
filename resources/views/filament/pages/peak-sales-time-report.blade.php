<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $filterTargets = 'data.from, data.to';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    {{-- style inline (bukan class grid-cols-*) -- panel Filament tidak
         compile Tailwind project ini, lihat catatan di SalesResource. --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem;">
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
                        <th class="whitespace-nowrap py-2 px-3">Waktu</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Penjualan (Rp)</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Penjualan (%)</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Transaksi</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Transaksi (%)</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Produk</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Produk (%)</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Pelanggan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['count'] === 0 ? 'text-gray-400 dark:text-gray-500' : '' }}">
                            <td class="whitespace-nowrap py-2 px-3 font-medium">{{ $row['dayName'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ $rupiah($row['revenue']) }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ number_format($row['revenuePct'], 1) }}%</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ $row['count'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ number_format($row['countPct'], 1) }}%</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ $row['products'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ number_format($row['productsPct'], 1) }}%</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ $row['customers'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
