<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $months = \App\Filament\Pages\MonthlyIncomeStatementReport::MONTHS;
        $fmt = fn (float $n) => abs($n) < 0.005 ? '–' : ($n < 0 ? '(' : '') . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : '');
        $profitAfter = ['beban_pokok' => 'laba_kotor', 'beban_operasional' => 'laba_operasional', 'beban_lain' => 'laba_sebelum_pajak', 'pajak' => 'laba_bersih'];
    @endphp

    @include('filament.partials.plain-ui')

    <x-filament::section>
        <x-slot name="heading">Laba Rugi Bulanan {{ $result['year'] }}</x-slot>
        <x-slot name="description">
            Toko: {{ $this->storeLabel() }} · dalam Rupiah. Dari jurnal yang sudah diposting; angka tiap bulan sama dengan Laporan Laba Rugi untuk bulan itu. Angka dalam tanda kurung berarti negatif.
        </x-slot>

        <div class="pu">
            <div class="pu-scroll">
                <table class="pu-table is-dense">
                    <thead>
                        <tr>
                            <th class="is-first">Akun</th>
                            @foreach ($months as $label)
                                <th class="is-num">{{ $label }}</th>
                            @endforeach
                            <th class="is-num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result['sections'] as $type => $section)
                            <tr class="is-section">
                                <td class="is-first" colspan="14" style="background: transparent;">{{ $section['label'] }}</td>
                            </tr>

                            @forelse ($section['rows'] as $row)
                                <tr>
                                    <td class="is-first">{{ $row['account']->code }} · {{ $row['account']->name }}</td>
                                    @foreach ($row['months'] as $value)
                                        <td class="is-num">{{ $fmt($value) }}</td>
                                    @endforeach
                                    <td class="is-num pu-strong">{{ $fmt($row['total']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="14" class="pu-muted">Tidak ada transaksi.</td></tr>
                            @endforelse

                            <tr class="is-total">
                                <td class="is-first">Total {{ $section['label'] }}</td>
                                @foreach ($section['months'] as $value)
                                    <td class="is-num">{{ $fmt($value) }}</td>
                                @endforeach
                                <td class="is-num">{{ $fmt($section['total']) }}</td>
                            </tr>

                            @if (isset($profitAfter[$type]))
                                @php $profit = $result['profits'][$profitAfter[$type]]; @endphp
                                <tr class="is-profit">
                                    <td class="is-first">{{ $profit['label'] }}</td>
                                    @foreach ($profit['months'] as $value)
                                        <td class="is-num {{ $value < 0 ? 'is-neg' : '' }}">{{ $fmt($value) }}</td>
                                    @endforeach
                                    <td class="is-num {{ $profit['total'] < 0 ? 'is-neg' : '' }}">{{ $fmt($profit['total']) }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
