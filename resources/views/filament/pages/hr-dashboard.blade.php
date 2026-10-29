<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $s = $this->getSummary();
        // Butir yang butuh tindakan: [label, jumlah, tautan]. Jumlah 0 ditampilkan sebagai beres.
        $actions = [
            ['Data karyawan wajib belum lengkap', $s['incomplete_required'], \App\Filament\Pages\EmployeeDataCompletenessReport::getUrl()],
            ['Kontrak sudah berakhir dan belum diperpanjang', $s['contracts_expired'], \App\Filament\Resources\ContractExtensionResource::getUrl()],
            ['Pengajuan izin/cuti menunggu keputusan', $s['pending_leave'], \App\Filament\Resources\LeaveRequestResource::getUrl()],
        ];

        // Butir menu HR yang disembunyikan (config/features.php) tidak ditampilkan.
        if (config('features.k3')) {
            $actions[] = ['APD lewat jatuh tempo ganti', $s['ppe_overdue'], \App\Filament\Resources\PpeIssuanceResource::getUrl()];
        }

        $actions[] = ['Sertifikat/pelatihan kedaluwarsa atau hampir (30 hari)', $s['trainings_expiring'], config('features.employee_training') ? \App\Filament\Resources\EmployeeTrainingResource::getUrl() : \App\Filament\Pages\InstallerCompetencyReport::getUrl()];

        if (config('features.k3')) {
            $actions[] = ['Kejadian K3 belum ditutup', $s['open_incidents'], \App\Filament\Resources\SafetyIncidentResource::getUrl()];
        }

        if (config('features.performance_reviews')) {
            $actions[] = ['Karyawan belum dinilai dalam 12 bulan', $s['not_reviewed'], \App\Filament\Resources\PerformanceReviewResource::getUrl()];
        }
    @endphp

    <div wire:loading.class="opacity-50" wire:target="data.store_id" class="space-y-6">
        @if ($s['open_injuries'] > 0)
            <div class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                {{ $s['open_injuries'] }} kejadian cedera belum ditutup. Cek kewajiban pelaporan ke BPJS Ketenagakerjaan.
            </div>
        @endif

        <x-filament::section heading="Perlu tindakan">
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($actions as [$label, $count, $url])
                    <li class="flex items-center justify-between gap-4 py-2 text-sm">
                        <a href="{{ $url }}" class="hover:underline">{{ $label }}</a>
                        @if ($count > 0)
                            <span class="rounded-md bg-warning-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ $count }}</span>
                        @else
                            <span class="text-xs text-success-600 dark:text-success-400">Beres</span>
                        @endif
                    </li>
                @endforeach
                @foreach ($s['policies_pending'] as $row)
                    <li class="flex items-center justify-between gap-4 py-2 text-sm">
                        <a href="{{ \App\Filament\Resources\CompanyPolicyResource::getUrl() }}" class="hover:underline">Belum menerima &ldquo;{{ $row['title'] }}&rdquo;</a>
                        <span class="rounded-md bg-warning-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ $row['pending'] }}</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>

        <div class="grid gap-6 md:grid-cols-2">
            <x-filament::section heading="Kontrak berakhir 30 hari ke depan ({{ count($s['contracts_expiring']) }})">
                @forelse ($s['contracts_expiring'] as $row)
                    <div class="flex justify-between py-1 text-sm"><span>{{ $row['name'] }}</span><span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $row['date'] }}</span></div>
                @empty
                    <div class="text-sm text-gray-500 dark:text-gray-400">Tidak ada.</div>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Masa percobaan segera berakhir ({{ count($s['probation_ending']) }})">
                @forelse ($s['probation_ending'] as $row)
                    <div class="flex justify-between py-1 text-sm"><span>{{ $row['name'] }}</span><span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $row['date'] }}</span></div>
                @empty
                    <div class="text-sm text-gray-500 dark:text-gray-400">Tidak ada.</div>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Karyawan aktif: {{ $s['headcount'] }}">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Per toko</div>
                @foreach ($s['by_store'] as $name => $count)
                    <div class="flex justify-between py-0.5 text-sm"><span>{{ $name }}</span><span class="tabular-nums">{{ $count }}</span></div>
                @endforeach
                <div class="mt-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Per tipe</div>
                @foreach ($s['by_type'] as $name => $count)
                    <div class="flex justify-between py-0.5 text-sm"><span>{{ $name }}</span><span class="tabular-nums">{{ $count }}</span></div>
                @endforeach
            </x-filament::section>

            <x-filament::section heading="Disiplin">
                <div class="flex justify-between py-0.5 text-sm"><span>Surat Peringatan masih berlaku</span><span class="tabular-nums">{{ $s['active_warnings'] }}</span></div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
