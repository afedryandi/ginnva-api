<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $months = \App\Filament\Pages\MonthlyIncomeStatementReport::MONTHS;
        $fmt = fn (float $n) => abs($n) < 0.005 ? '–' : ($n < 0 ? '(' : '') . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : '');
        $profitAfter = ['beban_pokok' => 'laba_kotor', 'beban_operasional' => 'laba_operasional', 'beban_lain' => 'laba_sebelum_pajak', 'pajak' => 'laba_bersih'];
        $sectionColor = ['pendapatan' => 'text-success-700 dark:text-success-400', 'beban_pokok' => 'text-danger-700 dark:text-danger-400', 'beban_operasional' => 'text-danger-700 dark:text-danger-400'];
    @endphp

    <x-filament::section>
        <x-slot name="heading">Laba Rugi Bulanan {{ $result['year'] }}</x-slot>
        <x-slot name="description">
            Toko: {{ $this->storeLabel() }} · dalam Rupiah. Dari jurnal yang sudah diposting; angka tiap bulan sama dengan Laporan Laba Rugi untuk bulan itu. Angka dalam tanda kurung berarti negatif.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-xs tabular-nums">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pe-3 text-left">Akun</th>
                        @foreach ($months as $label)
                            <th class="px-2 py-2 text-right">{{ $label }}</th>
                        @endforeach
                        <th class="px-2 py-2 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result['sections'] as $type => $section)
                        <tr>
                            <td colspan="14" class="pt-4 pb-1 text-sm font-semibold {{ $sectionColor[$type] ?? 'text-gray-700 dark:text-gray-300' }}">{{ $section['label'] }}</td>
                        </tr>
                        @forelse ($section['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="whitespace-nowrap py-1 pe-3 ps-3">{{ $row['account']->code }} · {{ $row['account']->name }}</td>
                                @foreach ($row['months'] as $value)
                                    <td class="px-2 py-1 text-right">{{ $fmt($value) }}</td>
                                @endforeach
                                <td class="px-2 py-1 text-right font-semibold">{{ $fmt($row['total']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="14" class="py-1 ps-3 text-gray-500 dark:text-gray-400">Tidak ada transaksi.</td></tr>
                        @endforelse
                        <tr class="border-t border-gray-300 font-semibold dark:border-white/20">
                            <td class="py-1 pe-3">Total {{ $section['label'] }}</td>
                            @foreach ($section['months'] as $value)
                                <td class="px-2 py-1 text-right">{{ $fmt($value) }}</td>
                            @endforeach
                            <td class="px-2 py-1 text-right">{{ $fmt($section['total']) }}</td>
                        </tr>

                        @if (isset($profitAfter[$type]))
                            @php $profit = $result['profits'][$profitAfter[$type]]; @endphp
                            <tr class="bg-gray-50 font-bold dark:bg-white/5">
                                <td class="py-1.5 pe-3 ps-1">{{ $profit['label'] }}</td>
                                @foreach ($profit['months'] as $value)
                                    <td class="px-2 py-1.5 text-right {{ $value < 0 ? 'text-danger-700 dark:text-danger-400' : '' }}">{{ $fmt($value) }}</td>
                                @endforeach
                                <td class="px-2 py-1.5 text-right {{ $profit['total'] < 0 ? 'text-danger-700 dark:text-danger-400' : '' }}">{{ $fmt($profit['total']) }}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
