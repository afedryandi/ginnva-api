<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $rows = $this->getRows();
        $filterTargets = 'data.from, data.to, data.store_id';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        <x-filament::section>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Durasi diambil dari waktu kendaraan masuk-keluar bengkel (SPK) — data AKTUAL, bukan estimasi.
                Kalau 1 job dikerjakan tim (lebih dari 1 installer), durasi PENUH dikreditkan ke setiap orang
                di tim itu (tidak dibagi rata). Laporan ini murni akumulasi — belum ada perhitungan komisi
                bertingkat, itu menunggu keputusan skema tier dari owner.
            </p>
        </x-filament::section>

        @if ($rows->isEmpty())
            <x-filament::section>
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <x-heroicon-o-clock class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada teknisi aktif untuk cabang ini.</p>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 pr-4">Teknisi</th>
                                <th class="py-2 pr-4">Cabang</th>
                                <th class="py-2 pr-4 text-right">Jumlah Job</th>
                                <th class="py-2 pr-4 text-right">Total Durasi</th>
                                <th class="py-2 pr-4 text-right">Rata-rata/Job</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 font-medium">{{ $row['name'] }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $row['store_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['job_count'], 0, ',', '.') }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums font-medium">{{ number_format($row['total_hours'], 1, ',', '.') }} jam</td>
                                    <td class="py-2 pr-4 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ $row['job_count'] > 0 ? number_format($row['avg_minutes_per_job'], 0, ',', '.') . ' menit' : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
