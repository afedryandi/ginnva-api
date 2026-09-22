<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $jobs = $this->getJobs();
        $filterTargets = 'data.from, data.to, data.store_id';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        <x-filament::section>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Durasi diambil dari waktu kendaraan masuk-keluar bengkel (SPK) — data AKTUAL, bukan estimasi.
                1 baris = 1 job (1 SPK). Untuk rekap akumulasi per teknisi (mis. persiapan komisi bertingkat),
                lihat menu "Durasi Servis Teknisi" di cluster Karyawan.
            </p>
        </x-filament::section>

        @if ($jobs->isEmpty())
            <x-filament::section>
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <x-heroicon-o-clock class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada job dengan waktu masuk-keluar tercatat pada rentang ini.</p>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">
                    {{ number_format($jobs->count(), 0, ',', '.') }} Job
                </x-slot>
                <x-slot name="description">
                    Rata-rata durasi: {{ number_format($jobs->avg('minutes') / 60, 1, ',', '.') }} jam
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 pr-4">Tanggal</th>
                                <th class="py-2 pr-4">No. SPK</th>
                                <th class="py-2 pr-4">Cabang</th>
                                <th class="py-2 pr-4">Customer</th>
                                <th class="py-2 pr-4">Jenis Layanan</th>
                                <th class="py-2 pr-4">Teknisi</th>
                                <th class="py-2 pr-4 text-right">Durasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($jobs as $job)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $job['date']->format('d M Y') }}</td>
                                    <td class="py-2 pr-4 font-medium">{{ $job['spk_number'] }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $job['store_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $job['customer_name'] }}</td>
                                    <td class="py-2 pr-4">
                                        @foreach ($job['services'] as $service)
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $service }}</span>
                                        @endforeach
                                    </td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ ! empty($job['technicians']) ? implode(', ', $job['technicians']) : '—' }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums font-medium">
                                        {{ intdiv($job['minutes'], 60) }}j {{ $job['minutes'] % 60 }}m
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
