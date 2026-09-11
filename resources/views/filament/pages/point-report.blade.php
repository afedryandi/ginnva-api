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
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem;">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Poin Didapatkan</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">+{{ number_format($result['totalEarned'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Poin Ditukar</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">-{{ number_format($result['totalSpent'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Poin Dibatalkan</div>
            <div class="mt-1 text-2xl font-bold tabular-nums italic text-gray-400 dark:text-gray-500">Tidak berlaku</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Tidak ada mekanisme pembatalan poin di sistem</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Rincian Poin per Hari</x-slot>
        <x-slot name="description">
            Gabungan poin Customer &amp; Partner. "Poin Didapat (Rp)" cuma valid untuk poin dari transaksi booking
            (nilai transaksi yang memicu poin, BUKAN nilai poinnya) — poin dari sumber lain (mis. klaim garansi) ditandai "—".
            "Nilai Tukar (Rp)" tidak ditampilkan — Reward tidak punya nilai Rupiah tersimpan.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Tanggal</th>
                        <th class="py-2 pr-3 text-right">Poin Didapat</th>
                        <th class="py-2 pr-3 text-right">Transaksi Dapat Poin</th>
                        <th class="py-2 pr-3 text-right">Poin Didapat (Rp)</th>
                        <th class="py-2 pr-3 text-right">Poin Ditukar</th>
                        <th class="py-2 pl-3 text-right">Transaksi Tukar Poin</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['earnCount'] === 0 && $row['spentCount'] === 0 ? 'text-gray-400 dark:text-gray-500' : '' }}">
                            <td class="py-2 pr-3 font-medium">{{ $row['label'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['earned'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['earnCount'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['earnRp'] > 0 ? $rupiah($row['earnRp']) : '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['spent'] }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $row['spentCount'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-center text-gray-500 dark:text-gray-400">Pilih rentang tanggal di atas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
