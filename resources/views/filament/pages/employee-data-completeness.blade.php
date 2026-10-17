<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $rows = $this->getFindings();
        $withRequired = $rows->filter(fn ($r) => $r['required'] !== []);
        $onlyRecommended = $rows->filter(fn ($r) => $r['required'] === []);
    @endphp

    <div wire:loading.class="opacity-50" wire:target="data.store_id" class="space-y-6">
        @if ($rows->isEmpty())
            <x-filament::section>
                <div class="text-sm text-success-600 dark:text-success-400">Semua data karyawan aktif sudah lengkap.</div>
            </x-filament::section>
        @endif

        @if ($withRequired->isNotEmpty())
            <x-filament::section heading="Wajib dilengkapi ({{ $withRequired->count() }} karyawan)" description="Tanpa ini gaji atau absensi bisa salah atau tidak bisa digenerate.">
                @foreach ($withRequired as $row)
                    <div class="border-t border-gray-200 py-3 first:border-t-0 dark:border-white/10">
                        <div class="text-sm font-medium">
                            <a href="{{ \App\Filament\Resources\UserResource::getUrl('edit', ['record' => $row['user']]) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['user']->name }}</a>
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $row['user']->store?->name ?? 'Tanpa toko' }}</span>
                        </div>
                        <ul class="mt-1 list-disc pl-5 text-sm text-danger-600 dark:text-danger-400">
                            @foreach ($row['required'] as $issue)
                                <li>{{ $issue }}</li>
                            @endforeach
                        </ul>
                        @if ($row['recommended'])
                            <ul class="mt-1 list-disc pl-5 text-xs text-gray-500 dark:text-gray-400">
                                @foreach ($row['recommended'] as $issue)
                                    <li>{{ $issue }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </x-filament::section>
        @endif

        @if ($onlyRecommended->isNotEmpty())
            <x-filament::section heading="Sebaiknya dilengkapi ({{ $onlyRecommended->count() }} karyawan)" description="Data kepegawaian formal; tidak menghalangi payroll.">
                @foreach ($onlyRecommended as $row)
                    <div class="border-t border-gray-200 py-3 first:border-t-0 dark:border-white/10">
                        <div class="text-sm font-medium">
                            <a href="{{ \App\Filament\Resources\UserResource::getUrl('edit', ['record' => $row['user']]) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['user']->name }}</a>
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $row['user']->store?->name ?? 'Tanpa toko' }}</span>
                        </div>
                        <ul class="mt-1 list-disc pl-5 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($row['recommended'] as $issue)
                                <li>{{ $issue }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
