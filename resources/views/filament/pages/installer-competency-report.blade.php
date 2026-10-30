<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $technicians = $this->getTechnicians();
        $services = $this->serviceLabels();
        $expiring = $this->getExpiringTrainings($technicians);
        $quality = $this->getQuality();
    @endphp

    <div class="space-y-6">
        <x-filament::section heading="Matriks keterampilan" description="Pemula = didampingi, Mandiri = boleh mengerjakan sendiri, Mahir = bisa melatih. Diisi di menu Installer > Keterampilan per Layanan.">
            @if ($technicians->isEmpty())
                <div class="text-sm text-gray-500 dark:text-gray-400">Belum ada installer di roster.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pe-4">Installer</th>
                                @foreach ($services as $label)
                                    <th class="py-2 pe-4">{{ $label }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($technicians as $technician)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="py-2 pe-4 font-medium">
                                        <a href="{{ \App\Filament\Resources\TechnicianResource::getUrl('view', ['record' => $technician]) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $technician->name }}</a>
                                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $technician->store?->name }}</span>
                                    </td>
                                    @foreach ($services as $key => $label)
                                        @php $skill = $technician->skills->firstWhere('service_type', $key); @endphp
                                        <td class="py-2 pe-4">
                                            @if ($skill)
                                                <span @class([
                                                    'rounded-md px-2 py-0.5 text-xs font-medium',
                                                    'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $skill->level === \App\Models\TechnicianSkill::EXPERT,
                                                    'bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400' => $skill->level === \App\Models\TechnicianSkill::INDEPENDENT,
                                                    'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => $skill->level === \App\Models\TechnicianSkill::BEGINNER,
                                                ])>{{ \App\Models\TechnicianSkill::LEVELS[$skill->level] }}</span>
                                            @else
                                                <span class="text-xs text-gray-400 dark:text-gray-500">Belum dinilai</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @if ($expiring->isNotEmpty())
            <x-filament::section heading="Sertifikat kedaluwarsa atau hampir kedaluwarsa ({{ $expiring->count() }})">
                <ul class="space-y-1 text-sm">
                    @foreach ($expiring as $row)
                        <li>
                            <span class="font-medium">{{ $row['technician']->name }}</span> &middot; {{ $row['training']->title }}
                            &middot;
                            @if ($row['training']->isExpired())
                                <span class="text-danger-600 dark:text-danger-400">kedaluwarsa {{ $row['training']->valid_until->format('d M Y') }}</span>
                            @else
                                <span class="text-warning-600 dark:text-warning-400">berlaku sampai {{ $row['training']->valid_until->format('d M Y') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section heading="Mutu pekerjaan" description="Dari booking selesai pada rentang tanggal. Untuk pembinaan, bukan dasar potongan. Pada booking tim, semua installer bertugas dihitung sama. Persentase baru berarti bila jumlah pekerjaan yang diperiksa cukup banyak.">
            @if ($quality->isEmpty())
                <div class="text-sm text-gray-500 dark:text-gray-400">Tidak ada booking selesai dengan installer pada rentang ini.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm tabular-nums">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pe-4">
                                    <button type="button" wire:click="sortQuality('name')" @class(['uppercase', 'text-primary-600 dark:text-primary-400' => $this->qualitySort === 'name'])>Installer</button>
                                </th>
                                <th class="py-2 pe-4 text-right">Pekerjaan selesai</th>
                                <th class="py-2 pe-4 text-right">Diperiksa QC</th>
                                <th class="py-2 pe-4 text-right">
                                    <button type="button" wire:click="sortQuality('rate')" title="Urutkan: lulus QC pertama terendah dulu" @class(['uppercase', 'text-primary-600 dark:text-primary-400' => $this->qualitySort === 'rate'])>Lulus QC pertama &#8645;</button>
                                </th>
                                <th class="py-2 pe-4 text-right">Pernah gagal QC</th>
                                <th class="py-2 pe-4 text-right">
                                    <button type="button" wire:click="sortQuality('rework')" title="Urutkan: pekerjaan ulang terbanyak dulu" @class(['uppercase', 'text-primary-600 dark:text-primary-400' => $this->qualitySort === 'rework'])>Pekerjaan ulang dicatat &#8645;</button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quality as $row)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="py-2 pe-4 font-medium">
                                        <button type="button" wire:click="toggleQualityJobs({{ $row['user_id'] }})" class="inline-flex items-center gap-1 hover:underline">
                                            <x-filament::icon :icon="$this->expandedUserId === $row['user_id'] ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'" class="h-4 w-4 text-gray-400" />
                                            {{ $row['name'] }}
                                        </button>
                                        @unless ($row['is_active'])
                                            <span class="ml-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300" title="Installer ini sudah nonaktif; tampil karena punya pekerjaan di periode ini.">Nonaktif</span>
                                        @endunless
                                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $row['store'] }}</span>
                                    </td>
                                    <td class="py-2 pe-4 text-right">{{ $row['jobs'] }}</td>
                                    <td class="py-2 pe-4 text-right">{{ $row['checked'] }}</td>
                                    <td class="py-2 pe-4 text-right">
                                        {{ $row['first_pass'] }}
                                        @if ($row['first_pass_rate'] !== null)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">({{ number_format($row['first_pass_rate'], 0) }}%)</span>
                                            @if ($row['small_sample'])
                                                <span class="ml-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300" title="Kurang dari {{ \App\Services\InstallerQualityService::SMALL_SAMPLE }} pekerjaan diperiksa QC: persentase belum bisa dijadikan patokan.">Sampel kecil</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="py-2 pe-4 text-right">{{ $row['reworked'] }}</td>
                                    <td class="py-2 pe-4 text-right">{{ $row['rework_recorded'] }}</td>
                                </tr>
                                @if ($this->expandedUserId === $row['user_id'])
                                    <tr class="border-b border-gray-100 bg-gray-50 dark:border-white/5 dark:bg-white/5">
                                        <td colspan="6" class="px-4 py-3">
                                            <table class="w-full text-xs">
                                                <thead>
                                                    <tr class="text-left uppercase text-gray-500 dark:text-gray-400">
                                                        <th class="py-1 pe-3">Booking</th>
                                                        <th class="py-1 pe-3">Tanggal</th>
                                                        <th class="py-1 pe-3">QC</th>
                                                        <th class="py-1">Pekerjaan ulang</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($this->getQualityJobs() as $job)
                                                        <tr @class(['text-danger-700 dark:text-danger-400' => $job['attention']])>
                                                            <td class="py-1 pe-3">
                                                                @if ($url = $this->bookingUrl($job['booking_id']))
                                                                    <a href="{{ $url }}" class="underline">{{ $job['booking_number'] }}</a>
                                                                @else
                                                                    {{ $job['booking_number'] }}
                                                                @endif
                                                            </td>
                                                            <td class="py-1 pe-3 tabular-nums">{{ $job['date']->format('d M Y') }}</td>
                                                            <td class="py-1 pe-3">{{ $job['qc'] }}</td>
                                                            <td class="py-1">{{ $job['reworks'] ? implode('; ', $job['reworks']) : '—' }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
