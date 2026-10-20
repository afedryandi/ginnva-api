<x-filament-panels::page>
    @php($closings = $this->getClosings())

    <x-filament::section>
        <x-slot name="heading">Riwayat Penutupan Tahun Buku</x-slot>
        <x-slot name="description">
            Penutupan memindahkan laba (rugi) tahun itu ke Laba Ditahan (3200) lewat jurnal penutup per toko bertanggal 31 Desember. Laporan Laba Rugi tahun itu tetap menampilkan angka sebenarnya; Neraca tidak berubah. Tutup tahun sebelum menutup periode Desember.
        </x-slot>

        @if ($closings->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada tahun buku yang ditutup.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm tabular-nums">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pe-4">Tahun</th>
                            <th class="py-2 pe-4 text-right">Laba (Rugi) Bersih</th>
                            <th class="py-2 pe-4 text-right">Jurnal</th>
                            <th class="py-2 pe-4">Ditutup</th>
                            <th class="py-2 pe-4">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($closings as $closing)
                            <tr class="border-b border-gray-100 align-top last:border-0 dark:border-white/5">
                                <td class="py-2 pe-4 font-medium">{{ $closing->year }}</td>
                                <td class="py-2 pe-4 text-right">{{ ($closing->net_income < 0 ? '-' : '') . 'Rp ' . number_format(abs($closing->net_income), 0, ',', '.') }}</td>
                                <td class="py-2 pe-4 text-right">{{ $closing->entries_count }}</td>
                                <td class="py-2 pe-4">{{ $closing->closed_at?->format('d M Y H:i') }}<div class="text-xs text-gray-500 dark:text-gray-400">{{ $closing->closer?->name }}</div></td>
                                <td class="py-2 pe-4">
                                    @if ($closing->isActive())
                                        <span class="text-success-600 dark:text-success-400">Ditutup</span>
                                    @else
                                        <span class="text-gray-500">Dibatalkan</span>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $closing->void_reason }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
