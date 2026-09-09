<x-filament-panels::page>
    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
    @endphp

    <x-filament::section>
        <div class="text-xs text-gray-500 dark:text-gray-400">Total Nilai Persediaan</div>
        <div class="mt-1 text-2xl font-bold tabular-nums">{{ $rupiah($result['totalValue']) }}</div>
        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Kondisi stok &amp; harga modal terkini — bukan snapshot per tanggal (sistem tidak menyimpan riwayat harga modal).</div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Persediaan per Item</x-slot>
        <x-slot name="description">Diurutkan dari nilai tertinggi. Gabungan Bahan Baku &amp; Barang Habis Pakai.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Nama Produk</th>
                        <th class="py-2 pr-3">SKU</th>
                        <th class="py-2 pr-3">Jenis</th>
                        <th class="py-2 pr-3">Kategori</th>
                        <th class="py-2 pr-3 text-right">Kuantitas</th>
                        <th class="py-2 pr-3">Satuan</th>
                        <th class="py-2 pr-3 text-right">Harga Modal</th>
                        <th class="py-2 pl-3 text-right">Total Nilai Persediaan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $row['name'] }}</td>
                            <td class="py-2 pr-3 font-mono">{{ $row['sku'] }}</td>
                            <td class="py-2 pr-3">{{ $row['type'] }}</td>
                            <td class="py-2 pr-3">{{ $row['category'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['quantity'], 2) }}</td>
                            <td class="py-2 pr-3">{{ $row['unit'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $rupiah($row['unitCost']) }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums font-medium">{{ $rupiah($row['totalValue']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-4 text-center text-gray-500 dark:text-gray-400">Belum ada item persediaan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
