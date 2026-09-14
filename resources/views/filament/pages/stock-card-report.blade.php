<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
        $filterTargets = 'data.from, data.to, data.jenis';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
        Awal &amp; Akhir direkonstruksi dari stok saat ini + histori pergerakan (sistem tidak menyimpan snapshot stok harian).
        "Masuk" = pencatatan masuk + penyesuaian positif; "Keluar" = pemakaian + |penyesuaian negatif| + stok terbuang.
        Rumus: Awal + Masuk − Keluar = Akhir. Stok masih nasional (belum per cabang).
    </div>

    <x-filament::section>
        <x-slot name="heading">Kartu Stok — {{ $result['from']->translatedFormat('d M Y') }} s/d {{ $result['to']->translatedFormat('d M Y') }}</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="whitespace-nowrap py-2 px-3">SKU</th>
                        <th class="whitespace-nowrap py-2 px-3">Nama</th>
                        <th class="whitespace-nowrap py-2 px-3">Jenis</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Awal</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Masuk</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Keluar</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Akhir</th>
                        <th class="whitespace-nowrap py-2 px-3">Satuan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['hasMovement'] ? '' : 'text-gray-400 dark:text-gray-500' }}">
                            <td class="whitespace-nowrap py-2 px-3 font-mono text-xs">{{ $row['code'] ?: '—' }}</td>
                            <td class="whitespace-nowrap py-2 px-3 font-medium">{{ $row['name'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3">{{ $row['jenis'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ $num($row['awal']) }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums text-success-600 dark:text-success-400">{{ $row['masuk'] > 0 ? '+'.$num($row['masuk']) : '0' }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums text-danger-600 dark:text-danger-400">{{ $row['keluar'] > 0 ? '−'.$num($row['keluar']) : '0' }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums font-semibold">{{ $num($row['akhir']) }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-gray-500 dark:text-gray-400">{{ $row['unit'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada bahan pada jenis ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
