<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
    @endphp

    @if (! $result)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Pilih akun dulu untuk melihat Buku Besar-nya.</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ $result['account']->display_name }}</x-slot>
            <x-slot name="description">
                Rincian mutasi jurnal posted dalam rentang tanggal yang dipilih, dengan saldo berjalan.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">Tanggal</th>
                            <th class="py-2 pr-4">No. Jurnal</th>
                            <th class="py-2 pr-4">Keterangan</th>
                            <th class="py-2 pr-4 text-right">Debit</th>
                            <th class="py-2 pr-4 text-right">Kredit</th>
                            <th class="py-2 pr-4 text-right">Saldo Berjalan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-gray-100 bg-gray-50 dark:border-white/5 dark:bg-white/5">
                            <td class="py-2 pr-4" colspan="5"><em>Saldo Awal</em></td>
                            <td class="py-2 pr-4 text-right font-semibold tabular-nums">{{ $rupiah($result['opening_balance']) }}</td>
                        </tr>

                        @forelse ($result['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $row['entry_date']->format('d M Y') }}</td>
                                <td class="py-2 pr-4 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['entry_number'] }}</td>
                                <td class="py-2 pr-4">{{ $row['description'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $row['debit'] > 0 ? $rupiah($row['debit']) : '—' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $row['credit'] > 0 ? $rupiah($row['credit']) : '—' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($row['running_balance']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="py-4 text-sm text-gray-500 dark:text-gray-400" colspan="6">Tidak ada mutasi di rentang tanggal ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-bold dark:border-white/20">
                            <td class="py-2 pr-4" colspan="3">Total Mutasi Periode Ini</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($result['total_debit']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($result['total_credit']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($result['closing_balance']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
