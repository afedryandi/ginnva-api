<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $rows = $this->getFindings();
        $summary = $this->getSummary();
        $withRequired = $rows->filter(fn ($r) => $r['required'] !== []);
        $onlyData = $rows->filter(fn ($r) => $r['required'] === [] && $r['recommended_data'] !== []);
        $onlyFollowUp = $rows->filter(fn ($r) => $r['required'] === [] && $r['recommended_data'] === []);
        $blocker = $this->getPayrollBlocker();
        $cannotFix = $rows->isNotEmpty() && ! \App\Filament\Resources\UserResource::canEdit($rows->first()['user']);
    @endphp

    <div wire:loading.class="opacity-50" wire:target="data.store_id,data.search" class="space-y-6">
        @if ($blocker)
            <x-filament::section>
                <div class="text-sm text-danger-600 dark:text-danger-400">
                    {{ $blocker['message'] }}
                    @if ($blocker['url'])
                        <a href="{{ $blocker['url'] }}" class="ml-1 underline">Atur aturan potongan</a>
                    @endif
                </div>
            </x-filament::section>
        @endif

        @if ($cannotFix)
            <x-filament::section>
                <div class="text-sm text-gray-600 dark:text-gray-300">Data karyawan hanya bisa diubah oleh akun akses penuh. Daftar ini bisa Anda gunakan untuk meminta mereka melengkapinya; unduh lewat tombol di kanan atas bila perlu.</div>
            </x-filament::section>
        @endif

        @if ($rows->isEmpty())
            <x-filament::section>
                <div class="text-sm text-success-600 dark:text-success-400">Tidak ada data karyawan aktif yang kurang untuk filter ini.</div>
            </x-filament::section>
        @else
            <x-filament::section heading="Ringkasan per masalah" description="Jumlah karyawan untuk tiap jenis masalah; merah berarti wajib.">
                <div class="flex flex-wrap gap-2">
                    @foreach ($summary as $item)
                        <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs {{ $item['required'] ? 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' : 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300' }}">
                            {{ $item['label'] }}
                            <span class="font-semibold">{{ $item['count'] }}</span>
                        </span>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        @foreach ([
            ['rows' => $withRequired, 'heading' => 'Wajib dilengkapi', 'description' => 'Tanpa ini gaji atau absensi bisa salah atau tidak bisa digenerate.'],
            ['rows' => $onlyData, 'heading' => 'Sebaiknya dilengkapi', 'description' => 'Data kepegawaian formal; tidak menghalangi payroll.'],
            ['rows' => $onlyFollowUp, 'heading' => 'Perlu ditindaklanjuti', 'description' => 'Bukan data yang kosong, tetapi butuh keputusan atau tindakan: masa percobaan, gaji di luar golongan, dokumen yang belum diterima.'],
        ] as $group)
            @if ($group['rows']->isNotEmpty())
                <x-filament::section :heading="$group['heading'] . ' (' . $group['rows']->count() . ' karyawan)'" :description="$group['description']">
                    @foreach ($group['rows'] as $row)
                        @php
                            $userUrl = $this->userUrl($row['user']);
                            $storeUrl = in_array('store_hours', $row['required_kinds'], true) ? $this->storeUrl($row['user']) : null;
                        @endphp
                        <div class="border-t border-gray-200 py-3 first:border-t-0 dark:border-white/10">
                            <div class="text-sm font-medium">
                                @if ($userUrl)
                                    <a href="{{ $userUrl }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['user']->name }}</a>
                                @else
                                    {{ $row['user']->name }}
                                @endif
                                <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $row['user']->store?->name ?? 'Tanpa toko' }}</span>
                                @if ($storeUrl)
                                    <a href="{{ $storeUrl }}" class="ml-2 text-xs font-normal text-primary-600 hover:underline dark:text-primary-400">Atur jam operasional toko</a>
                                @endif
                            </div>
                            @if ($row['required'])
                                <ul class="mt-1 list-disc pl-5 text-sm text-danger-600 dark:text-danger-400">
                                    @foreach ($row['required'] as $issue)
                                        <li>{{ $issue }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($row['recommended_data'])
                                <ul class="mt-1 list-disc pl-5 text-sm {{ $row['required'] ? 'text-xs text-gray-500 dark:text-gray-400' : 'text-gray-600 dark:text-gray-300' }}">
                                    @foreach ($row['recommended_data'] as $issue)
                                        <li>{{ $issue }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($row['follow_up'])
                                <ul class="mt-1 list-disc pl-5 text-sm {{ $row['required'] || $row['recommended_data'] ? 'text-xs text-gray-500 dark:text-gray-400' : 'text-gray-600 dark:text-gray-300' }}">
                                    @foreach ($row['follow_up'] as $issue)
                                        <li>{{ $issue }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </x-filament::section>
            @endif
        @endforeach
    </div>
</x-filament-panels::page>
