<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Jasa Terjual</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Pendapatan</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($result['totalRevenue']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Rata-rata per Jasa</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ $rupiah($result['avgRevenue']) }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Per Jenis Servis</x-slot>
        <x-slot name="description">Booking dengan 2 produk sekaligus dibagi rata 50/50, sama logika Jurnal Umum.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Jenis</th>
                        <th class="py-2 pr-3 text-right">Jumlah</th>
                        <th class="py-2 pl-3 text-right">Pendapatan</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-3 font-medium">Kaca Film</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ $result['byType']['kaca_film']['count'] }}</td>
                        <td class="py-2 pl-3 text-right tabular-nums">{{ $rupiah($result['byType']['kaca_film']['revenue']) }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 font-medium">PPF</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ $result['byType']['ppf']['count'] }}</td>
                        <td class="py-2 pl-3 text-right tabular-nums">{{ $rupiah($result['byType']['ppf']['revenue']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Per Toko</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pr-3 text-right">Jumlah</th>
                        <th class="py-2 pl-3 text-right">Pendapatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['byStore'] as $storeName => $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $storeName }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['count'] }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $rupiah($row['revenue']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-4 text-center text-gray-500 dark:text-gray-400">Belum ada data pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
