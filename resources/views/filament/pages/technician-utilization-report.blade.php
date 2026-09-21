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
                <strong>Jam Hadir</strong>: sungguhan dari absensi (clock-in/clock-out). <strong>Jam Job</strong>:
                estimasi dari hari kerja yang direncanakan per booking (booking belum mencatat jam mulai/selesai
                kerja yang sesungguhnya). Utilisasi di atas 100% bukan bug — bisa berarti job dikerjakan di luar jam
                normal, dikerjakan tim, atau estimasi durasinya lebih besar dari kenyataan.
            </p>
        </x-filament::section>

        @if ($rows->isEmpty())
            <x-filament::section>
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <x-heroicon-o-user-group class="h-10 w-10 text-gray-300 dark:text-gray-600" />
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
                                <th class="py-2 pr-4 text-right">Jam Hadir</th>
                                <th class="py-2 pr-4 text-right">Jam Job (estimasi)</th>
                                <th class="py-2 pr-4 text-right">Jam Idle</th>
                                <th class="py-2 pr-4 text-right">Utilisasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 font-medium">{{ $row['name'] }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $row['store_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">
                                        @if (! $row['has_account'])
                                            <span class="text-xs text-gray-400" title="Teknisi ini belum punya akun login, tidak ada data absensi">Belum ada akun</span>
                                        @else
                                            {{ number_format($row['present_hours'], 1, ',', '.') }} jam
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['job_hours'], 1, ',', '.') }} jam</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">
                                        {{ $row['idle_hours'] !== null ? number_format($row['idle_hours'], 1, ',', '.') . ' jam' : '—' }}
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        @if ($row['utilization_percent'] === null)
                                            <span class="text-gray-400">—</span>
                                        @else
                                            @php
                                                $pct = $row['utilization_percent'];
                                                $color = $pct >= 100 ? 'warning' : ($pct >= 60 ? 'success' : 'danger');
                                            @endphp
                                            <x-filament::badge :color="$color">{{ number_format($pct, 1, ',', '.') }}%</x-filament::badge>
                                        @endif
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
