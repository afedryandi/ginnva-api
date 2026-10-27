<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $r = $this->getResult();
        $pct = fn ($v) => $v === null ? '-' : number_format($v, 1, ',', '.') . '%';
        $hours = fn ($v) => $v === null ? '-' : number_format($v, 1, ',', '.') . ' jam';
        $filterTargets = 'data.from, data.to, data.store_id';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        {{-- Grid pakai style inline (panel memakai CSS bawaan Filament, bukan hasil compile Tailwind project ini). --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:1rem;">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Booking Diajukan</div>
                <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($r['created'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $r['pending'] }} masih menunggu konfirmasi</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Konversi ke Terkonfirmasi</div>
                <div class="mt-1 text-2xl font-bold tabular-nums">{{ $pct($r['conversion_percent']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $r['reached_confirmed'] }} dari {{ $r['created'] }} booking</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Konfirmasi dalam Target ({{ $r['target_hours'] }} jam)</div>
                <div class="mt-1 text-2xl font-bold tabular-nums">{{ $pct($r['within_target_percent']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $r['within_target'] }} dari {{ $r['confirmed_measured'] }} yang tercatat waktunya</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Lama Tunggu Konfirmasi</div>
                <div class="mt-1 text-2xl font-bold tabular-nums">{{ $hours($r['median_wait_hours']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">median; rata-rata {{ $hours($r['avg_wait_hours']) }}</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Tingkat Tidak Datang</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-warning-600 dark:text-warning-400">{{ $pct($r['no_show_percent']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $r['no_show'] }} tidak datang, {{ $r['completed'] }} selesai</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Dibatalkan (selain tidak datang)</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($r['cancelled'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $pct($r['cancelled_percent']) }} dari booking diajukan</div>
            </x-filament::section>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:1rem;">
            <x-filament::section heading="Dibatalkan oleh">
                @if (empty($r['cancelled_by']))
                    <div class="text-sm text-gray-500 dark:text-gray-400">Tidak ada pembatalan pada periode ini.</div>
                @else
                    <table class="w-full text-sm">
                        @foreach ($r['cancelled_by'] as $who => $total)
                            <tr>
                                <td class="py-1">{{ ['customer' => 'Pelanggan', 'staff' => 'Staff', 'system' => 'Sistem'][$who] ?? ucfirst($who) }}</td>
                                <td class="py-1 text-right tabular-nums">{{ $total }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </x-filament::section>

            <x-filament::section heading="Alasan pembatalan terbanyak">
                @if (empty($r['cancel_reasons']))
                    <div class="text-sm text-gray-500 dark:text-gray-400">Belum ada alasan tercatat.</div>
                @else
                    <table class="w-full text-sm">
                        @foreach ($r['cancel_reasons'] as $row)
                            <tr>
                                <td class="py-1">{{ $row['reason'] }}</td>
                                <td class="py-1 text-right tabular-nums">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </x-filament::section>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Periode menurut waktu booking diajukan. Waktu tunggu konfirmasi hanya dihitung untuk booking yang dikonfirmasi sejak fitur ini aktif;
            booking lama tidak punya waktu konfirmasi yang andal dan tidak ikut rata-rata. Tidak datang = booking terkonfirmasi yang ditandai pelanggan tidak datang.
        </p>
    </div>
</x-filament-panels::page>
