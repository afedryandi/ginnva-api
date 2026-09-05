<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $months = $this->getMonths();
        $year = (int) ($this->data['year'] ?? now()->year);
    @endphp

    <x-filament::section>
        <x-slot name="heading">Status Periode {{ $year }}</x-slot>
        <x-slot name="description">
            Bulan yang ditutup tidak bisa lagi menerima jurnal baru/perubahan/posting — koreksi hanya lewat Jurnal Pembalik (bertanggal hari ini) atau buka kembali periodenya dulu.
        </x-slot>

        <div class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($months as $m)
                <div class="flex items-center justify-between py-3">
                    <div>
                        <div class="font-medium">{{ $m['date']->translatedFormat('F Y') }}</div>
                        @if ($m['is_closed'])
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                Ditutup {{ $m['period']->closed_at?->format('d M Y H:i') }} oleh {{ $m['period']->closer?->name ?? '—' }}
                                @if ($m['period']->notes)
                                    — {{ $m['period']->notes }}
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-3">
                        @if ($m['is_closed'])
                            <x-filament::badge color="success">Ditutup</x-filament::badge>
                            <x-filament::button
                                size="sm"
                                color="warning"
                                wire:click="reopenMonth({{ $year }}, {{ $m['month'] }})"
                                wire:confirm="Yakin buka kembali periode {{ $m['date']->translatedFormat('F Y') }}? Jurnal dengan tanggal di bulan ini akan bisa dibuat/diubah/diposting lagi."
                            >
                                Buka Kembali
                            </x-filament::button>
                        @elseif ($m['is_future'])
                            <x-filament::badge color="gray">Belum Terjadi</x-filament::badge>
                        @else
                            <x-filament::badge color="gray">Terbuka</x-filament::badge>
                            <x-filament::button
                                size="sm"
                                color="danger"
                                wire:click="closeMonth({{ $year }}, {{ $m['month'] }})"
                                wire:confirm="Yakin tutup periode {{ $m['date']->translatedFormat('F Y') }}? Jurnal dengan tanggal di bulan ini tidak akan bisa dibuat/diubah/diposting lagi sampai dibuka kembali."
                            >
                                Tutup Periode
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
