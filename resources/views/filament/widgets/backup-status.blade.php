<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Cadangan Database
        </x-slot>

        @php($status = $this->getStatus())

        <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
            <x-filament::icon
                :icon="$status['state'] === 'ok' ? 'heroicon-o-shield-check' : 'heroicon-o-exclamation-triangle'"
                @class([
                    'h-6 w-6 shrink-0',
                    'text-success-500' => $status['state'] === 'ok',
                    'text-warning-500' => $status['state'] === 'stale',
                    'text-danger-500' => $status['state'] === 'missing',
                ])
            />

            <div class="min-w-0 text-sm">
                @if ($status['found'])
                    <p class="font-semibold text-gray-950 dark:text-white">
                        Cadangan terakhir: {{ $status['at']->translatedFormat('j F Y, H:i') }}
                        <span class="font-normal text-gray-500 dark:text-gray-400">({{ $status['size_mb'] }} MB, {{ $status['age_hours'] }} jam lalu)</span>
                    </p>
                    @if ($status['stale'])
                        <p class="text-warning-600 dark:text-warning-400">
                            Sudah lebih dari {{ \App\Support\BackupStatus::STALE_AFTER_HOURS }} jam. Jadwal harian jam 03:00 mungkin tidak berjalan, minta tim IT memeriksa.
                        </p>
                    @else
                        <p class="text-gray-500 dark:text-gray-400">Jadwal harian jam 03:00 berjalan. Tersimpan {{ $status['count'] }} berkas terakhir di server.</p>
                    @endif
                @else
                    <p class="font-semibold text-danger-600 dark:text-danger-400">Belum ada berkas cadangan di server ini.</p>
                    <p class="text-gray-500 dark:text-gray-400">Minta tim IT memeriksa jadwal cadangan harian.</p>
                @endif

                <p class="mt-1 text-gray-500 dark:text-gray-400">
                    Salinan di luar server (off-site):
                    @if ($status['offsite_configured'])
                        sudah diatur.
                    @else
                        <span class="font-medium text-warning-600 dark:text-warning-400">belum diatur</span>, cadangan hanya tersimpan di server yang sama.
                    @endif
                </p>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
