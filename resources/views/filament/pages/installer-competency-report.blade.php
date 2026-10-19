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
        <x-filament::section heading="Matriks keterampilan" description="Pemula = didampingi, Mandiri = boleh mengerjakan sendiri, Mahir = bisa melatih. Diisi di menu Teknisi > Keterampilan per Layanan.">
            @if ($technicians->isEmpty())
                <div class="text-sm text-gray-500 dark:text-gray-400">Belum ada teknisi di roster.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pe-4">Teknisi</th>
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
                                <th class="py-2 pe-4">Installer</th>
                                <th class="py-2 pe-4 text-right">Pekerjaan selesai</th>
                                <th class="py-2 pe-4 text-right">Diperiksa QC</th>
                                <th class="py-2 pe-4 text-right">Lulus QC pertama</th>
                                <th class="py-2 pe-4 text-right">Pernah gagal QC</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quality as $row)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="py-2 pe-4 font-medium">{{ $row['name'] }}<span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $row['store'] }}</span></td>
                                    <td class="py-2 pe-4 text-right">{{ $row['jobs'] }}</td>
                                    <td class="py-2 pe-4 text-right">{{ $row['checked'] }}</td>
                                    <td class="py-2 pe-4 text-right">
                                        {{ $row['first_pass'] }}
                                        @if ($row['first_pass_rate'] !== null)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">({{ number_format($row['first_pass_rate'], 0) }}%)</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pe-4 text-right">{{ $row['reworked'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
