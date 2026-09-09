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
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Komisi Periode Ini</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($result['totalCommission']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Teknisi Punya Pekerjaan Tapi Komisi Belum Diatur</div>
            <div class="mt-1 text-2xl font-bold tabular-nums {{ $result['unratedCount'] > 0 ? 'text-warning-600 dark:text-warning-400' : '' }}">{{ $result['unratedCount'] }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Komisi per Teknisi</x-slot>
        <x-slot name="description">
            Nominal tetap per pekerjaan × jumlah booking (sudah terbayar/tercatat di Jurnal Umum) yang ditugaskan ke teknisi tersebut.
            Kalau 1 booking dikerjakan >1 teknisi, masing-masing dihitung penuh (tidak dibagi).
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Teknisi</th>
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pr-3 text-right">Jumlah Pekerjaan</th>
                        <th class="py-2 pr-3 text-right">Komisi/Pekerjaan</th>
                        <th class="py-2 pl-3 text-right">Total Komisi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $row['technician']->name }}</td>
                            <td class="py-2 pr-3">{{ $row['technician']->store?->name ?? '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['jobCount'] }}</td>
                            @if ($row['rate'] !== null)
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $rupiah($row['rate']) }}</td>
                                <td class="py-2 pl-3 text-right tabular-nums font-medium">{{ $rupiah($row['totalCommission']) }}</td>
                            @else
                                <td class="py-2 pr-3 text-right italic text-gray-400 dark:text-gray-500">Belum diatur</td>
                                <td class="py-2 pl-3 text-right italic text-gray-400 dark:text-gray-500">Belum diatur</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-gray-500 dark:text-gray-400">Belum ada data teknisi bertaut akun installer.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($result['unratedCount'] > 0)
            <p class="mt-3 text-xs text-warning-600 dark:text-warning-400">
                Ada {{ $result['unratedCount'] }} teknisi yang punya pekerjaan di periode ini tapi belum diatur nominal komisinya —
                atur di menu Teknisi supaya total komisi di atas akurat.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
