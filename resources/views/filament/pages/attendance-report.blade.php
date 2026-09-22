<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $entryTypeLabel = fn (string $type) => match ($type) {
            'clock' => 'Clock In/Out',
            'manual' => 'Manual',
            'field_duty' => 'Tugas Lapangan',
            'alpha' => 'Alpha',
            'leave' => 'Izin/Cuti',
            default => $type,
        };
        $filterTargets = 'data.from, data.to, data.store_id';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    {{-- style inline (bukan class grid-cols-*) -- panel Filament tidak
         compile Tailwind project ini, lihat catatan di SalesResource. --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:1rem;">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Tepat Waktu</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ number_format($result['onTimeCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Terlambat Masuk</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($result['lateCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Pulang Cepat</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-warning-600 dark:text-warning-400">{{ number_format($result['earlyLeaveCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Masuk Lebih Awal</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-info-600 dark:text-info-400">{{ number_format($result['earlyArrivalCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Lembur/Pulang Lambat</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-info-600 dark:text-info-400">{{ number_format($result['overtimeCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Alpha</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($result['alphaCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Izin/Cuti</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['leaveCount'], 0, ',', '.') }}</div>
        </x-filament::section>
    </div>

    @if ($result['patternByUser']->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Pola Ketepatan Waktu per Karyawan</x-slot>
            <x-slot name="description">
                "Masuk Lebih Awal" & "Lembur/Pulang Lambat" dibandingkan terhadap Jadwal Kerja yang berlaku (menu Karyawan → Jadwal Kerja) —
                karyawan yang belum di-assign jadwal untuk hari itu masuk kolom "Tanpa Jadwal", bukan dihitung 0. Kategori TIDAK saling
                eksklusif (1 hari bisa masuk 2 kategori sekaligus, mis. telat tapi juga lembur).
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-3">Nama</th>
                            <th class="py-2 pr-3 text-right">Terlambat Masuk</th>
                            <th class="py-2 pr-3 text-right">Pulang Cepat</th>
                            <th class="py-2 pr-3 text-right">Masuk Lebih Awal</th>
                            <th class="py-2 pr-3 text-right">Lembur/Pulang Lambat</th>
                            <th class="py-2 pl-3 text-right">Tanpa Jadwal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result['patternByUser'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-3 font-medium">{{ $row['user']->name }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums {{ $row['lateCount'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $row['lateCount'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums {{ $row['earlyLeaveCount'] > 0 ? 'text-warning-600 dark:text-warning-400' : '' }}">{{ $row['earlyLeaveCount'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums {{ $row['earlyArrivalCount'] > 0 ? 'text-info-600 dark:text-info-400' : '' }}">{{ $row['earlyArrivalCount'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums {{ $row['overtimeCount'] > 0 ? 'text-info-600 dark:text-info-400' : '' }}">{{ $row['overtimeCount'] }}</td>
                                <td class="py-2 pl-3 text-right tabular-nums text-gray-400 dark:text-gray-500">{{ $row['noScheduleCount'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Rincian Absensi</x-slot>
        <x-slot name="description">
            "Jadwal Masuk/Pulang" per baris tidak ditampilkan di tabel ini (sistem cuma simpan hasil hitung telat/pulang cepat, bukan jam
            jadwalnya) — tapi "Masuk Lebih Awal"/"Lembur" di atas SUDAH bisa dihitung dari Jadwal Kerja yang berlaku, lihat tabel "Pola
            Ketepatan Waktu" di atas.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Nama</th>
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pr-3">Tanggal</th>
                        <th class="py-2 pr-3">Absen Masuk</th>
                        <th class="py-2 pr-3">Absen Keluar</th>
                        <th class="py-2 pr-3 text-right">Terlambat</th>
                        <th class="py-2 pr-3 text-right">Pulang Cepat</th>
                        <th class="py-2 pl-3">Jenis</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $row->user?->name ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $row->store?->name ?? '—' }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $row->date?->format('d M Y') }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $row->clock_in_at?->format('H:i') ?? '—' }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $row->clock_out_at?->format('H:i') ?? '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums {{ $row->late_minutes > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $row->late_minutes > 0 ? $row->late_minutes . ' menit' : '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums {{ $row->early_leave_minutes > 0 ? 'text-warning-600 dark:text-warning-400' : '' }}">{{ $row->early_leave_minutes > 0 ? $row->early_leave_minutes . ' menit' : '—' }}</td>
                            <td class="py-2 pl-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ in_array($row->entry_type, ['alpha']) ? 'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' : (in_array($row->entry_type, ['leave']) ? 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' : 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400') }}">
                                    {{ $entryTypeLabel($row->entry_type) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada data absensi pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
