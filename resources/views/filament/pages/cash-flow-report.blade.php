<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $sections = $result['sections'];
        $rupiah = fn ($n) => ($n < 0 ? '(' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : '');
    @endphp

    <x-filament::section>
        <x-slot name="heading">Laporan Arus Kas</x-slot>
        <x-slot name="description">
            Metode langsung — dari Jurnal Umum berstatus posted yang menyentuh akun kas (Kas di Tangan / Kas di Bank).
        </x-slot>

        <div class="space-y-6 text-sm">
            <div class="flex justify-between rounded-lg bg-gray-50 px-3 py-2 font-semibold dark:bg-white/5">
                <span>Saldo Kas Awal Periode</span>
                <span class="tabular-nums">{{ $rupiah($result['opening_cash']) }}</span>
            </div>

            @foreach ($sections as $section)
                <div>
                    <div class="mb-1 font-semibold text-gray-700 dark:text-gray-300">{{ $section['label'] }}</div>
                    @forelse ($section['rows'] as $row)
                        <div class="flex justify-between border-b border-gray-100 py-1.5 pl-3 dark:border-white/5">
                            <span class="text-gray-600 dark:text-gray-300">
                                {{ $row['entry_date']->format('d M') }} —
                                {{ $row['description'] }}
                                <span class="font-mono text-xs text-gray-400">({{ $row['entry_number'] }})</span>
                            </span>
                            <span class="tabular-nums">{{ $rupiah($row['amount']) }}</span>
                        </div>
                    @empty
                        <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada arus kas di kategori ini.</p>
                    @endforelse
                    <div class="flex justify-between border-t border-gray-200 pt-1.5 font-semibold dark:border-white/10">
                        <span>Total {{ $section['label'] }}</span>
                        <span class="tabular-nums">{{ $rupiah($section['total']) }}</span>
                    </div>
                </div>
            @endforeach

            <div class="flex justify-between rounded-lg bg-gray-50 px-3 py-2 font-semibold dark:bg-white/5">
                <span>Kenaikan (Penurunan) Kas Bersih</span>
                <span class="tabular-nums">{{ $rupiah($result['net_change']) }}</span>
            </div>

            <div @class([
                'flex justify-between rounded-lg px-3 py-3 text-base font-bold',
                'bg-success-100 text-success-800 dark:bg-success-900 dark:text-success-200' => $result['closing_cash'] >= 0,
                'bg-danger-100 text-danger-800 dark:bg-danger-900 dark:text-danger-200' => $result['closing_cash'] < 0,
            ])>
                <span>Saldo Kas Akhir Periode</span>
                <span class="tabular-nums">{{ $rupiah($result['closing_cash']) }}</span>
            </div>
        </div>

        <div class="mt-6">
            @if ($result['is_reconciled'])
                <div class="rounded-lg border border-success-300 bg-success-50 p-3 text-sm text-success-700 dark:border-success-700 dark:bg-success-950 dark:text-success-300">
                    ✓ Sudah sesuai dengan saldo aktual akun kas ({{ $rupiah($result['closing_cash_actual']) }}).
                </div>
            @else
                <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-300">
                    ✗ Saldo akhir hasil perhitungan ({{ $rupiah($result['closing_cash']) }}) berbeda dari saldo aktual akun kas ({{ $rupiah($result['closing_cash_actual']) }}) — periksa jurnal.
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
