<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $r = $this->getResult();
        $t = $r['totals'];
        $pct = fn ($v) => $v === null ? '-' : number_format($v, 1, ',', '.') . '%';
        $hours = fn ($v) => $v === null ? '-' : number_format($v, 1, ',', '.') . ' jam';
        $filterTargets = 'data.from, data.to, data.type';

        // Tabel ringkas yang dipakai berulang: kolom sama untuk jenis, kanal, dan kampanye.
        $headers = ['Masuk', 'Direspons', 'Median Respons', 'Dalam Target', 'Deal', 'Tidak Jadi', 'Terbuka', 'Tingkat Deal'];
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        {{-- Grid pakai style inline (panel memakai CSS bawaan Filament, bukan hasil compile Tailwind project ini). --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:1rem;">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Lead Masuk</div>
                <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($t['total'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $t['open'] }} masih terbuka</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Sudah Direspons</div>
                <div class="mt-1 text-2xl font-bold tabular-nums">{{ $pct($t['responded_percent']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $t['responded'] }} dari {{ $t['total'] }}</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Respons dalam Target ({{ $r['sla_hours'] }} jam)</div>
                <div class="mt-1 text-2xl font-bold tabular-nums">{{ $pct($t['within_sla_percent']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">median {{ $hours($t['median_response_hours']) }}</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Belum Ditindak &amp; Telat</div>
                <div class="mt-1 text-2xl font-bold tabular-nums {{ $t['overdue_unanswered'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ number_format($t['overdue_unanswered'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">lebih dari {{ $r['sla_hours'] }} jam berstatus Baru</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Tingkat Deal</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ $pct($t['win_rate_percent']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $t['won'] }} deal, {{ $t['lost'] }} tidak jadi</div>
            </x-filament::section>
        </div>

        @foreach ([['Menurut Jenis Lead', $r['by_type']], ['Menurut Kanal', $r['by_channel']], ['Menurut Kampanye (utm_campaign)', $r['by_campaign']]] as [$title, $rows])
            <x-filament::section :heading="$title">
                @if (empty($rows))
                    <div class="text-sm text-gray-500 dark:text-gray-400">Belum ada data pada periode ini.</div>
                @else
                    <div style="overflow-x:auto;">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                    <th class="py-1 pr-3"></th>
                                    @foreach ($headers as $h)
                                        <th class="py-1 px-2 text-right">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr class="border-t border-gray-200 dark:border-white/10">
                                        <td class="py-1.5 pr-3 font-medium">{{ $row['label'] }}</td>
                                        <td class="py-1.5 px-2 text-right tabular-nums">{{ $row['total'] }}</td>
                                        <td class="py-1.5 px-2 text-right tabular-nums">{{ $row['responded'] }}</td>
                                        <td class="py-1.5 px-2 text-right tabular-nums">{{ $hours($row['median_response_hours']) }}</td>
                                        <td class="py-1.5 px-2 text-right tabular-nums">{{ $pct($row['within_sla_percent']) }}</td>
                                        <td class="py-1.5 px-2 text-right tabular-nums">{{ $row['won'] }}</td>
                                        <td class="py-1.5 px-2 text-right tabular-nums">{{ $row['lost'] }}</td>
                                        <td class="py-1.5 px-2 text-right tabular-nums">{{ $row['open'] }}</td>
                                        <td class="py-1.5 px-2 text-right tabular-nums">{{ $pct($row['win_rate_percent']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach

        <x-filament::section heading="Alasan Tidak Jadi Terbanyak">
            @if (empty($r['lost_reasons']))
                <div class="text-sm text-gray-500 dark:text-gray-400">Belum ada lead tidak jadi dengan alasan tercatat.</div>
            @else
                <table class="w-full text-sm">
                    @foreach ($r['lost_reasons'] as $row)
                        <tr>
                            <td class="py-1">{{ $row['reason'] }}</td>
                            <td class="py-1 text-right tabular-nums">{{ $row['total'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </x-filament::section>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Periode menurut waktu lead masuk. Waktu respons = dari lead masuk sampai pertama kali ditindak (status berganti dari Baru); hanya lead
            yang sudah ditindak sejak fitur ini aktif yang tercatat waktunya. Untuk kemitraan, status Deal dihitung Deal dan Ditolak dihitung Tidak Jadi
            bila kolom Hasil belum diisi. Kampanye terisi dari UTM situs; lead tanpa UTM tidak muncul di tabel kampanye.
        </p>
    </div>
</x-filament-panels::page>
