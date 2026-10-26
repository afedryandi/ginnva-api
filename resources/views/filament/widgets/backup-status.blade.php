<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Cadangan Database
        </x-slot>

        @php($status = $this->getStatus())
        @include('filament.partials.plain-ui')

        <div class="pu" style="display: flex; align-items: flex-start; gap: 12px;">
            <x-filament::icon
                :icon="$status['state'] === 'ok' ? 'heroicon-o-shield-check' : 'heroicon-o-exclamation-triangle'"
                style="width: 28px; height: 28px; flex-shrink: 0;"
                @class([
                    'pu-ok' => $status['state'] === 'ok',
                    'pu-warn' => $status['state'] === 'stale',
                    'pu-danger' => $status['state'] === 'missing',
                ])
            />

            <div class="pu-stack" style="gap: 4px; min-width: 0;">
                @if ($status['found'])
                    <div class="pu-strong">
                        Cadangan terakhir: {{ $status['at']->copy()->locale('id')->translatedFormat('j F Y, H:i') }}
                        <span class="pu-muted" style="font-weight: 400;">({{ $status['size_mb'] }} MB, {{ $status['age_hours'] }} jam lalu)</span>
                    </div>
                    @if ($status['stale'])
                        <div class="pu-warn">
                            Sudah lebih dari {{ \App\Support\BackupStatus::STALE_AFTER_HOURS }} jam. Jadwal harian jam 03:00 mungkin tidak berjalan, minta tim IT memeriksa.
                        </div>
                    @else
                        <div class="pu-muted">Jadwal harian jam 03:00 berjalan. Tersimpan {{ $status['count'] }} berkas terakhir di server.</div>
                    @endif
                @else
                    <div class="pu-strong pu-danger">Belum ada berkas cadangan di server ini.</div>
                    <div class="pu-muted">Minta tim IT memeriksa jadwal cadangan harian.</div>
                @endif

                <div class="pu-muted">
                    Salinan di luar server (off-site):
                    @if ($status['offsite_configured'])
                        sudah diatur.
                    @else
                        <span class="pu-warn" style="font-weight: 600;">belum diatur</span>, cadangan hanya tersimpan di server yang sama.
                    @endif
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
