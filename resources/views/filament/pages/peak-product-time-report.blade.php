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
    @if ($result['unassignedCount'] > 0)
        <div class="rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300">
            {{ $result['unassignedCount'] }} dari {{ $result['totalCount'] }} transaksi belum diisi varian produk (SKU) spesifiknya —
            laporan ini baru akurat sepenuhnya kalau staff konsisten mengisi field "Varian Produk (SKU)" saat input/edit booking.
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">Produk × Hari Tersibuk</x-slot>
        <x-slot name="description">Diurutkan dari jumlah transaksi tertinggi. "Hari" diambil dari tanggal booking tercatat sebagai pendapatan.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="whitespace-nowrap py-2 px-3">Produk</th>
                        <th class="whitespace-nowrap py-2 px-3">Hari</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Jumlah</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Jumlah %</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Penjualan</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Penjualan %</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['product'] === null ? 'italic text-gray-400 dark:text-gray-500' : '' }}">
                            <td class="whitespace-nowrap py-2 px-3 font-medium">{{ $row['product'] ? "{$row['product']->sku} — {$row['product']->name}" : 'Belum Diisi SKU' }}</td>
                            <td class="whitespace-nowrap py-2 px-3">{{ $row['dayName'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ $row['count'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ number_format($row['countPct'], 1) }}%</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ $rupiah($row['revenue']) }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ number_format($row['revenuePct'], 1) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-center text-gray-500 dark:text-gray-400">Belum ada data pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
