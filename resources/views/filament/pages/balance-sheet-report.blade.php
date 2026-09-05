<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
    @endphp

    <x-filament::section>
        <x-slot name="heading">Neraca per {{ $result['as_of']->format('d M Y') }}</x-slot>
        <x-slot name="description">
            Dari Jurnal Umum berstatus posted — "Laba (Rugi) Tahun Berjalan" dihitung otomatis dari 1 Januari {{ $result['as_of']->year }} s.d. tanggal ini (belum ada mekanisme Tutup Periode, jadi belum "dipindahkan" resmi ke Laba Ditahan).
        </x-slot>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- ASET --}}
            <div>
                <div class="mb-2 text-base font-bold text-info-700 dark:text-info-400">Aset</div>
                @forelse ($result['aset']['rows'] as $row)
                    <div class="flex justify-between border-b border-gray-100 py-1.5 text-sm dark:border-white/5">
                        <span class="text-gray-600 dark:text-gray-300">{{ $row['account']->name }}</span>
                        <span class="tabular-nums">{{ $rupiah($row['balance']) }}</span>
                    </div>
                @empty
                    <p class="text-xs text-gray-500 dark:text-gray-400">Belum ada saldo aset.</p>
                @endforelse
                <div class="mt-2 flex justify-between border-t-2 border-gray-300 pt-2 text-sm font-bold dark:border-white/20">
                    <span>Total Aset</span>
                    <span class="tabular-nums">{{ $rupiah($result['aset']['total']) }}</span>
                </div>
            </div>

            {{-- KEWAJIBAN & MODAL --}}
            <div class="space-y-6">
                <div>
                    <div class="mb-2 text-base font-bold text-warning-700 dark:text-warning-400">Kewajiban</div>
                    @forelse ($result['kewajiban']['rows'] as $row)
                        <div class="flex justify-between border-b border-gray-100 py-1.5 text-sm dark:border-white/5">
                            <span class="text-gray-600 dark:text-gray-300">{{ $row['account']->name }}</span>
                            <span class="tabular-nums">{{ $rupiah($row['balance']) }}</span>
                        </div>
                    @empty
                        <p class="text-xs text-gray-500 dark:text-gray-400">Belum ada saldo kewajiban.</p>
                    @endforelse
                    <div class="mt-2 flex justify-between border-t border-gray-200 pt-1.5 text-sm font-semibold dark:border-white/10">
                        <span>Total Kewajiban</span>
                        <span class="tabular-nums">{{ $rupiah($result['kewajiban']['total']) }}</span>
                    </div>
                </div>

                <div>
                    <div class="mb-2 text-base font-bold text-success-700 dark:text-success-400">Modal</div>
                    @forelse ($result['modal']['rows'] as $row)
                        <div class="flex justify-between border-b border-gray-100 py-1.5 text-sm dark:border-white/5">
                            <span class="text-gray-600 dark:text-gray-300">{{ $row['account']->name }}</span>
                            <span class="tabular-nums">{{ $rupiah($row['balance']) }}</span>
                        </div>
                    @empty
                        <p class="text-xs text-gray-500 dark:text-gray-400">Belum ada saldo modal.</p>
                    @endforelse
                    <div class="flex justify-between border-b border-gray-100 py-1.5 text-sm italic dark:border-white/5">
                        <span class="text-gray-600 dark:text-gray-300">Laba (Rugi) Tahun Berjalan</span>
                        <span class="tabular-nums">{{ $rupiah($result['modal']['laba_tahun_berjalan']) }}</span>
                    </div>
                    <div class="mt-2 flex justify-between border-t border-gray-200 pt-1.5 text-sm font-semibold dark:border-white/10">
                        <span>Total Modal</span>
                        <span class="tabular-nums">{{ $rupiah($result['modal']['total']) }}</span>
                    </div>
                </div>

                <div class="flex justify-between border-t-2 border-gray-300 pt-2 text-sm font-bold dark:border-white/20">
                    <span>Total Kewajiban + Modal</span>
                    <span class="tabular-nums">{{ $rupiah($result['total_kewajiban_modal']) }}</span>
                </div>
            </div>
        </div>

        <div class="mt-6">
            @if ($result['is_balanced'])
                <div class="rounded-lg border border-success-300 bg-success-50 p-3 text-sm text-success-700 dark:border-success-700 dark:bg-success-950 dark:text-success-300">
                    ✓ Balance — Total Aset ({{ $rupiah($result['aset']['total']) }}) sama dengan Total Kewajiban + Modal.
                </div>
            @else
                <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-300">
                    ✗ TIDAK balance — Total Aset ({{ $rupiah($result['aset']['total']) }}) berbeda dari Total Kewajiban + Modal ({{ $rupiah($result['total_kewajiban_modal']) }}). Periksa jurnal yang mungkin belum lengkap.
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
