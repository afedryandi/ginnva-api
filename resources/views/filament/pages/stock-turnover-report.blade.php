<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $filterTargets = 'data.from, data.to';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
        Rasio Perputaran = Qty Keluar ÷ Rata-rata Stok (stok awal &amp; akhir rentang, direkonstruksi dari histori pergerakan
        — sistem tidak menyimpan snapshot stok harian). Rasio tinggi = bahan cepat berputar, bukan otomatis "bagus"/"buruk".
        Bahan yang tidak ada pergerakan sama sekali di rentang ini tidak ditampilkan.
    </div>

    <x-filament::section>
        <x-slot name="heading">Perputaran per Bahan Baku &amp; Barang Habis Pakai</x-slot>
        <x-slot name="description">Diurutkan dari rasio tertinggi.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="whitespace-nowrap py-2 px-3">Nama</th>
                        <th class="whitespace-nowrap py-2 px-3">Jenis</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Terpakai</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Sisa</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Rata-rata Stok</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Perputaran Stok</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Hari Terjual</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="whitespace-nowrap py-2 px-3 font-medium">{{ $row['item']->name }}</td>
                            <td class="whitespace-nowrap py-2 px-3">{{ $row['type'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ number_format($row['qtyOut'], 2) }} {{ $row['item']->unit }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ number_format($row['stockAtTo'], 2) }} {{ $row['item']->unit }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ number_format($row['avgStock'], 2) }} {{ $row['item']->unit }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums font-medium">{{ $row['turnoverRatio'] !== null ? number_format($row['turnoverRatio'], 2) . 'x' : '—' }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ $row['daysSold'] }} Hari</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada pergerakan stok pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
