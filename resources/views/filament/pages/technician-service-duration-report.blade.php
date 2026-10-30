<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $rows = $this->getRows();
        $unfinished = $this->getUnfinishedSpkCount();
        $totalMinutes = $rows->sum('total_minutes');
        $totalJobs = $rows->sum('job_count');
        $filterTargets = 'data.from, data.to, data.store_id';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        <x-filament::section>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Durasi dihitung dari kendaraan masuk sampai keluar bengkel (waktu check-in dan check-out SPK), jadi
                kendaraan yang menginap dihitung penuh. Job yang dikerjakan tim dikreditkan penuh ke setiap installer
                di tim itu, tidak dibagi rata. Laporan ini hanya merekap durasi dan belum dipakai untuk menghitung komisi.
                Installer yang sudah nonaktif tetap tampil bila punya job di periode ini. Ketuk nama installer untuk melihat job yang membentuk angkanya.
                @if ($jobReportUrl = $this->jobReportUrl())
                    Durasi per job ada di <a href="{{ $jobReportUrl }}" class="underline">laporan Proses Order</a>.
                @endif
            </p>
        </x-filament::section>

        @if ($unfinished > 0)
            <div class="rounded-lg bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                {{ $unfinished }} SPK di periode ini belum di-check-out padahal kendaraan masuk lebih dari sehari lalu,
                jadi tidak ikut terhitung. Minta installer atau leader menekan check-out agar durasinya masuk.
            </div>
        @endif

        @if ($rows->isEmpty())
            <x-filament::section>
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <x-heroicon-o-clock class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada installer aktif untuk toko ini.</p>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 pr-4">Installer</th>
                                <th class="py-2 pr-4">Toko</th>
                                <th class="py-2 pr-4 text-right">Jumlah Job</th>
                                <th class="py-2 pr-4 text-right">Total Durasi</th>
                                <th class="py-2 pr-4 text-right">Rata-rata/Job</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 font-medium">
                                        @if ($row['has_account'] && $row['job_count'] > 0)
                                            <button type="button" wire:click="toggleJobs({{ $row['technician_id'] }})" class="inline-flex items-center gap-1 hover:underline">
                                                <x-filament::icon :icon="$this->expandedTechnicianId === $row['technician_id'] ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'" class="h-4 w-4 text-gray-400" />
                                                {{ $row['name'] }}
                                            </button>
                                        @else
                                            {{ $row['name'] }}
                                        @endif
                                        @unless ($row['is_active'])
                                            <span class="ml-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300"
                                                  title="Installer ini sudah nonaktif; tampil karena punya job di periode ini.">Nonaktif</span>
                                        @endunless
                                        @unless ($row['has_account'])
                                            <span class="ml-1 rounded-md bg-warning-50 px-1.5 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400"
                                                  title="Installer ini belum punya akun, jadi jobnya tidak bisa dilacak dan angkanya selalu 0.">Belum punya akun</span>
                                        @endunless
                                    </td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $row['store_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['job_count'], 0, ',', '.') }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums font-medium">{{ number_format($row['total_hours'], 1, ',', '.') }} jam</td>
                                    <td class="py-2 pr-4 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ $row['job_count'] > 0 ? number_format($row['avg_minutes_per_job'], 0, ',', '.') . ' menit' : '—' }}
                                    </td>
                                </tr>
                                @if ($this->expandedTechnicianId === $row['technician_id'])
                                    <tr class="border-b border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-white/5">
                                        <td colspan="5" class="px-4 py-3">
                                            <div class="overflow-x-auto">
                                                <table class="w-full text-xs">
                                                    <thead>
                                                        <tr class="text-left uppercase text-gray-500 dark:text-gray-400">
                                                            <th class="py-1 pr-3">SPK</th>
                                                            <th class="py-1 pr-3">Booking</th>
                                                            <th class="py-1 pr-3">Masuk</th>
                                                            <th class="py-1 pr-3">Keluar</th>
                                                            <th class="py-1 pr-3 text-right">Durasi</th>
                                                            <th class="py-1">Tim</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($this->getExpandedJobs() as $job)
                                                            <tr>
                                                                <td class="py-1 pr-3 font-mono">{{ $job['spk_number'] ?? '—' }}</td>
                                                                <td class="py-1 pr-3">
                                                                    @if ($url = $this->bookingUrl($job['booking_id']))
                                                                        <a href="{{ $url }}" class="underline">{{ $job['booking_number'] }}</a>
                                                                    @else
                                                                        {{ $job['booking_number'] ?? '—' }}
                                                                    @endif
                                                                </td>
                                                                <td class="py-1 pr-3 tabular-nums">{{ $job['checked_in_at']->format('d M H:i') }}</td>
                                                                <td class="py-1 pr-3 tabular-nums">{{ $job['checked_out_at']->format('d M H:i') }}</td>
                                                                <td class="py-1 pr-3 text-right tabular-nums">
                                                                    {{ number_format($job['minutes'] / 60, 1, ',', '.') }} jam
                                                                    @if ($job['long'])
                                                                        <span class="ml-1 rounded-md bg-warning-50 px-1.5 py-0.5 font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400" title="Lebih dari {{ \App\Services\TechnicianServiceDurationService::LONG_JOB_HOURS }} jam: kemungkinan kendaraan menginap, bukan jam kerja.">Panjang</span>
                                                                    @endif
                                                                </td>
                                                                <td class="py-1 text-gray-500 dark:text-gray-400">{{ $job['team'] ? implode(', ', $job['team']) : '—' }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-300 font-semibold dark:border-gray-600">
                                <td class="py-2 pr-4" colspan="2">Total <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(job tim terhitung per orang)</span></td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($totalJobs, 0, ',', '.') }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($totalMinutes / 60, 1, ',', '.') }} jam</td>
                                <td class="py-2 pr-4 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ $totalJobs > 0 ? number_format((int) round($totalMinutes / $totalJobs), 0, ',', '.') . ' menit' : '—' }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
