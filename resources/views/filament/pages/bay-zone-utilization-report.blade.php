<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $filterTargets = 'data.from, data.to, data.store_id';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        @if (! $result['store'])
            <x-filament::section>
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <x-heroicon-o-building-storefront class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Pilih toko terlebih dahulu.</p>
                </div>
            </x-filament::section>
        @elseif (! $result['configured'])
            <x-filament::section>
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <x-heroicon-o-exclamation-triangle class="h-10 w-10 text-warning-400" />
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">
                        Toko ini belum diisi kapasitas slot — isi dulu di halaman Toko/Dealer.
                    </p>
                </div>
            </x-filament::section>
        @else
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Utilisasi dihitung dari durasi NYATA tiap booking menempati tahap-tahap zona ini (bukan estimasi
                rata-rata) — beda dari "Laporan Reservasi & Utilisasi" yang cuma menghitung jumlah booking per hari.
                Zona yang belum diisi jumlah slotnya ditandai "Belum dikonfigurasi" dan dilewati dari perhitungan,
                bukan dianggap 0 slot.
            </p>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($result['zones'] as $zone)
                    <x-filament::section>
                        <x-slot name="heading">{{ $zone['label'] }}</x-slot>

                        @if (! $zone['configured'])
                            <p class="text-sm italic text-gray-400 dark:text-gray-500">Belum dikonfigurasi.</p>
                        @else
                            <dl class="grid grid-cols-2 gap-y-3 text-sm">
                                <dt class="text-gray-500 dark:text-gray-400">Kapasitas (Slot)</dt>
                                <dd class="text-right font-medium tabular-nums">{{ $zone['slotCount'] }}</dd>

                                <dt class="text-gray-500 dark:text-gray-400">Jumlah Booking Lewat Zona Ini</dt>
                                <dd class="text-right font-medium tabular-nums">{{ number_format($zone['bookingCount'], 0, ',', '.') }}</dd>

                                <dt class="text-gray-500 dark:text-gray-400">Jam Tersedia</dt>
                                <dd class="text-right font-medium tabular-nums">{{ number_format($zone['availableHours'], 1, ',', '.') }} jam</dd>

                                <dt class="text-gray-500 dark:text-gray-400">Jam Terpakai</dt>
                                <dd class="text-right font-medium tabular-nums">{{ number_format($zone['occupiedHours'], 1, ',', '.') }} jam</dd>

                                <dt class="text-gray-500 dark:text-gray-400">Utilisasi %</dt>
                                <dd class="text-right font-bold tabular-nums text-primary-600 dark:text-primary-400">{{ number_format($zone['utilizationPct'], 1, ',', '.') }}%</dd>

                                <dt class="text-gray-500 dark:text-gray-400">Peak Bersamaan</dt>
                                <dd class="text-right font-medium tabular-nums">{{ $zone['peakConcurrent'] }}</dd>
                            </dl>
                        @endif
                    </x-filament::section>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
