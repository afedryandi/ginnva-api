<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $filterTargets = 'data.month';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    {{-- style inline (bukan class grid-cols-*) -- panel Filament tidak
         compile Tailwind project ini, lihat catatan di SalesResource. --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem;">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Gaji Bersih</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ $rupiah($result['totalNetPay']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Hari Alpha</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($result['totalAlphaDays'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Menit Telat</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-warning-600 dark:text-warning-400">{{ number_format($result['totalLateMinutes'], 0, ',', '.') }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Rekap per Karyawan — {{ $result['month']->translatedFormat('F Y') }}</x-slot>
        <x-slot name="description">Diambil langsung dari baris Penggajian bulan terpilih — kalau belum digenerate, tabel ini kosong (generate dulu lewat menu Penggajian).</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Karyawan</th>
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pr-3 text-right">Hari Kerja</th>
                        <th class="py-2 pr-3 text-right">Telat (Menit)</th>
                        <th class="py-2 pr-3 text-right">Alpha (Hari)</th>
                        <th class="py-2 pr-3 text-right">Gaji Bersih</th>
                        <th class="py-2 pl-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['payrolls'] as $payroll)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $payroll->user?->name ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $payroll->store?->name ?? '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $payroll->working_days_in_month }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums {{ $payroll->total_late_minutes > 0 ? 'text-warning-600 dark:text-warning-400' : '' }}">{{ $payroll->total_late_minutes }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums {{ $payroll->alpha_days > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $payroll->alpha_days }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums font-medium">{{ $rupiah($payroll->net_pay) }}</td>
                            <td class="py-2 pl-3">
                                @if ($payroll->status === 'paid')
                                    <span class="fi-badge inline-flex items-center rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">Sudah Dibayar</span>
                                @else
                                    <span class="fi-badge inline-flex items-center rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">Draft</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-center text-gray-500 dark:text-gray-400">Belum ada payroll digenerate untuk bulan ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
