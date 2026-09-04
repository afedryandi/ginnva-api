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
        <x-slot name="heading">Laporan Laba Rugi</x-slot>
        <x-slot name="description">
            Dari Jurnal Umum berstatus posted dalam rentang tanggal yang dipilih.
        </x-slot>

        <div class="space-y-6 text-sm">
            {{-- Pendapatan --}}
            <div>
                <div class="mb-1 font-semibold text-success-700 dark:text-success-400">{{ $sections['pendapatan']['label'] }}</div>
                @forelse ($sections['pendapatan']['rows'] as $row)
                    <div class="flex justify-between border-b border-gray-100 py-1.5 pl-3 dark:border-white/5">
                        <span class="text-gray-600 dark:text-gray-300">{{ $row['account']->name }}</span>
                        <span class="tabular-nums">{{ $rupiah($row['amount']) }}</span>
                    </div>
                @empty
                    <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada transaksi.</p>
                @endforelse
                <div class="flex justify-between border-t border-gray-200 pt-1.5 font-semibold dark:border-white/10">
                    <span>Total Pendapatan</span>
                    <span class="tabular-nums">{{ $rupiah($sections['pendapatan']['total']) }}</span>
                </div>
            </div>

            {{-- HPP --}}
            <div>
                <div class="mb-1 font-semibold text-danger-700 dark:text-danger-400">{{ $sections['beban_pokok']['label'] }}</div>
                @forelse ($sections['beban_pokok']['rows'] as $row)
                    <div class="flex justify-between border-b border-gray-100 py-1.5 pl-3 dark:border-white/5">
                        <span class="text-gray-600 dark:text-gray-300">{{ $row['account']->name }}</span>
                        <span class="tabular-nums">{{ $rupiah($row['amount']) }}</span>
                    </div>
                @empty
                    <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada transaksi.</p>
                @endforelse
                <div class="flex justify-between border-t border-gray-200 pt-1.5 font-semibold dark:border-white/10">
                    <span>Total HPP</span>
                    <span class="tabular-nums">{{ $rupiah($sections['beban_pokok']['total']) }}</span>
                </div>
            </div>

            <div @class([
                'flex justify-between rounded-lg px-3 py-2 text-base font-bold',
                'bg-success-50 text-success-700 dark:bg-success-950 dark:text-success-300' => $result['laba_kotor'] >= 0,
                'bg-danger-50 text-danger-700 dark:bg-danger-950 dark:text-danger-300' => $result['laba_kotor'] < 0,
            ])>
                <span>Laba Kotor</span>
                <span class="tabular-nums">{{ $rupiah($result['laba_kotor']) }}</span>
            </div>

            {{-- Beban Operasional --}}
            <div>
                <div class="mb-1 font-semibold text-danger-700 dark:text-danger-400">{{ $sections['beban_operasional']['label'] }}</div>
                @forelse ($sections['beban_operasional']['rows'] as $row)
                    <div class="flex justify-between border-b border-gray-100 py-1.5 pl-3 dark:border-white/5">
                        <span class="text-gray-600 dark:text-gray-300">{{ $row['account']->name }}</span>
                        <span class="tabular-nums">{{ $rupiah($row['amount']) }}</span>
                    </div>
                @empty
                    <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada transaksi.</p>
                @endforelse
                <div class="flex justify-between border-t border-gray-200 pt-1.5 font-semibold dark:border-white/10">
                    <span>Total Beban Operasional</span>
                    <span class="tabular-nums">{{ $rupiah($sections['beban_operasional']['total']) }}</span>
                </div>
            </div>

            <div @class([
                'flex justify-between rounded-lg px-3 py-2 text-base font-bold',
                'bg-success-50 text-success-700 dark:bg-success-950 dark:text-success-300' => $result['laba_operasional'] >= 0,
                'bg-danger-50 text-danger-700 dark:bg-danger-950 dark:text-danger-300' => $result['laba_operasional'] < 0,
            ])>
                <span>Laba Operasional</span>
                <span class="tabular-nums">{{ $rupiah($result['laba_operasional']) }}</span>
            </div>

            {{-- Pendapatan & Beban Lain-lain --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <div class="mb-1 font-semibold text-gray-700 dark:text-gray-300">{{ $sections['pendapatan_lain']['label'] }}</div>
                    @forelse ($sections['pendapatan_lain']['rows'] as $row)
                        <div class="flex justify-between border-b border-gray-100 py-1.5 pl-3 dark:border-white/5">
                            <span class="text-gray-600 dark:text-gray-300">{{ $row['account']->name }}</span>
                            <span class="tabular-nums">{{ $rupiah($row['amount']) }}</span>
                        </div>
                    @empty
                        <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada transaksi.</p>
                    @endforelse
                    <div class="flex justify-between pt-1.5 font-semibold">
                        <span>Total</span>
                        <span class="tabular-nums">{{ $rupiah($sections['pendapatan_lain']['total']) }}</span>
                    </div>
                </div>
                <div>
                    <div class="mb-1 font-semibold text-gray-700 dark:text-gray-300">{{ $sections['beban_lain']['label'] }}</div>
                    @forelse ($sections['beban_lain']['rows'] as $row)
                        <div class="flex justify-between border-b border-gray-100 py-1.5 pl-3 dark:border-white/5">
                            <span class="text-gray-600 dark:text-gray-300">{{ $row['account']->name }}</span>
                            <span class="tabular-nums">{{ $rupiah($row['amount']) }}</span>
                        </div>
                    @empty
                        <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada transaksi.</p>
                    @endforelse
                    <div class="flex justify-between pt-1.5 font-semibold">
                        <span>Total</span>
                        <span class="tabular-nums">{{ $rupiah($sections['beban_lain']['total']) }}</span>
                    </div>
                </div>
            </div>

            <div @class([
                'flex justify-between rounded-lg px-3 py-2 text-base font-bold',
                'bg-success-50 text-success-700 dark:bg-success-950 dark:text-success-300' => $result['laba_sebelum_pajak'] >= 0,
                'bg-danger-50 text-danger-700 dark:bg-danger-950 dark:text-danger-300' => $result['laba_sebelum_pajak'] < 0,
            ])>
                <span>Laba Sebelum Pajak</span>
                <span class="tabular-nums">{{ $rupiah($result['laba_sebelum_pajak']) }}</span>
            </div>

            {{-- Pajak --}}
            <div>
                <div class="mb-1 font-semibold text-gray-700 dark:text-gray-300">{{ $sections['pajak']['label'] }}</div>
                @forelse ($sections['pajak']['rows'] as $row)
                    <div class="flex justify-between border-b border-gray-100 py-1.5 pl-3 dark:border-white/5">
                        <span class="text-gray-600 dark:text-gray-300">{{ $row['account']->name }}</span>
                        <span class="tabular-nums">{{ $rupiah($row['amount']) }}</span>
                    </div>
                @empty
                    <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada transaksi.</p>
                @endforelse
            </div>

            <div @class([
                'flex justify-between rounded-lg px-3 py-3 text-lg font-bold',
                'bg-success-100 text-success-800 dark:bg-success-900 dark:text-success-200' => $result['laba_bersih'] >= 0,
                'bg-danger-100 text-danger-800 dark:bg-danger-900 dark:text-danger-200' => $result['laba_bersih'] < 0,
            ])>
                <span>Laba Bersih</span>
                <span class="tabular-nums">{{ $rupiah($result['laba_bersih']) }}</span>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
