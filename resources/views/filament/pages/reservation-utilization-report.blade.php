<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
    @endphp

    <div class="rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300">
        Kapasitas dihitung dari setting <strong>saat ini</strong> tiap toko (Kapasitas Instalasi/Hari), BUKAN kapasitas persis
        yang berlaku di hari tertentu di masa lalu — nominal itu diinput manual staff sesaat saat approve booking dan tidak
        pernah disimpan. Angka Utilisasi di sini pendekatan, bukan catatan historis pasti.
    </div>

    <x-filament::section>
        <x-slot name="heading">Utilisasi per Toko</x-slot>
        <x-slot name="description">Diurutkan dari utilisasi tertinggi. Hari libur toko dilewati dari perhitungan (tidak dihitung sebagai kapasitas kosong).</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pr-3 text-right">Hari Kerja</th>
                        <th class="py-2 pr-3 text-right">Kapasitas/Hari</th>
                        <th class="py-2 pr-3 text-right">Total Kapasitas</th>
                        <th class="py-2 pr-3 text-right">Terpakai</th>
                        <th class="py-2 pl-3">Utilisasi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $row['store']->name }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['workingDays'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['capacityPerDay'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['totalCapacity'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['totalUsed'] }}</td>
                            <td class="py-2 pl-3">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-24 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                        <div class="h-full rounded-full {{ $row['utilizationPct'] >= 80 ? 'bg-danger-500' : ($row['utilizationPct'] >= 50 ? 'bg-warning-500' : 'bg-success-500') }}" style="width: {{ $row['utilizationPct'] }}%"></div>
                                    </div>
                                    <span class="tabular-nums text-xs font-medium">{{ number_format($row['utilizationPct'], 1) }}%</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada toko yang bisa diakses.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
