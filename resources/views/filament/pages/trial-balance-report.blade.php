<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rows = $result['rows'];
        $rupiah = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
    @endphp

    <x-filament::section>
        <x-slot name="heading">Neraca Saldo</x-slot>
        <x-slot name="description">
            Saldo kumulatif tiap akun sejak awal sampai tanggal yang dipilih — dari Jurnal Umum berstatus posted saja.
        </x-slot>

        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada jurnal posted sampai tanggal ini.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">Kode</th>
                            <th class="py-2 pr-4">Nama Akun</th>
                            <th class="py-2 pr-4 text-right">Debit</th>
                            <th class="py-2 pr-4 text-right">Kredit</th>
                            <th class="py-2 pr-4 text-right">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['account']->code }}</td>
                                <td class="py-2 pr-4">{{ $row['account']->name }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $row['debit'] > 0 ? $rupiah($row['debit']) : '—' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $row['credit'] > 0 ? $rupiah($row['credit']) : '—' }}</td>
                                <td class="py-2 pr-4 text-right font-semibold tabular-nums">{{ $rupiah($row['balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-bold dark:border-white/20">
                            <td class="py-2 pr-4" colspan="2">Total</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($result['total_debit']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($result['total_credit']) }}</td>
                            <td class="py-2 pr-4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if (round($result['total_debit'], 2) !== round($result['total_credit'], 2))
                <div class="mt-4 rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-300">
                    Total debit dan kredit tidak sama — seharusnya tidak mungkin terjadi kalau semua jurnal posted lewat JournalEntryService. Segera periksa.
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
