<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $rows = $this->getRows();
        $filterTargets = 'data.from, data.to, data.store_id';
        $fmtHours = fn (float $minutes) => number_format($minutes / 60, 1, ',', '.') . ' jam';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        <x-filament::section>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                1 job dengan lebih dari 1 jenis layanan sekaligus (mis. PPF + Detailing) menyumbang durasi PENUHNYA
                ke setiap layanan itu (tidak dibagi) — Jumlah Job lintas baris bisa melebihi jumlah job sungguhan
                kalau banyak booking kombo. Untuk daftar per-job, lihat menu "Proses Order".
            </p>
        </x-filament::section>

        @if ($rows->isEmpty())
            <x-filament::section>
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <x-heroicon-o-chart-bar-square class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada job dengan waktu masuk-keluar tercatat pada rentang ini.</p>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 pr-4">Jenis Layanan</th>
                                <th class="py-2 pr-4 text-right">Jumlah Job</th>
                                <th class="py-2 pr-4 text-right">Rata-rata</th>
                                <th class="py-2 pr-4 text-right">Tercepat</th>
                                <th class="py-2 pr-4 text-right">Terlama</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 font-medium">{{ $row['service'] }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['jobCount'], 0, ',', '.') }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums font-medium">{{ $fmtHours($row['avgMinutes']) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums text-success-600 dark:text-success-400">{{ $fmtHours($row['minMinutes']) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums text-danger-600 dark:text-danger-400">{{ $fmtHours($row['maxMinutes']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
