<x-filament-panels::page>
    @php
        $aging = $this->getAging();
        $fmt = fn ($n) => (float) $n > 0 ? 'Rp ' . number_format((float) $n, 0, ',', '.') : '—';
    @endphp

    <x-filament::section>
        <x-slot name="heading">Sisa piutang per customer menurut umur jatuh tempo</x-slot>
        <x-slot name="description">
            Hanya piutang Belum Diterima / Diterima Sebagian. "Belum jatuh tempo" mencakup piutang tanpa tanggal jatuh tempo. Dihitung per hari ini.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pe-4">Customer</th>
                        <th class="py-2 px-3 text-right">Belum jatuh tempo</th>
                        <th class="py-2 px-3 text-right">1–30 hari</th>
                        <th class="py-2 px-3 text-right">31–60 hari</th>
                        <th class="py-2 px-3 text-right">61–90 hari</th>
                        <th class="py-2 px-3 text-right">&gt; 90 hari</th>
                        <th class="py-2 ps-3 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($aging['rows'] as $row)
                        <tr>
                            <td class="py-2 pe-4 font-medium">{{ $row->customer }}</td>
                            <td class="py-2 px-3 text-right tabular-nums">{{ $fmt($row->current_amt) }}</td>
                            <td class="py-2 px-3 text-right tabular-nums">{{ $fmt($row->b1) }}</td>
                            <td class="py-2 px-3 text-right tabular-nums text-warning-600">{{ $fmt($row->b2) }}</td>
                            <td class="py-2 px-3 text-right tabular-nums text-danger-600">{{ $fmt($row->b3) }}</td>
                            <td class="py-2 px-3 text-right tabular-nums font-semibold text-danger-600">{{ $fmt($row->b4) }}</td>
                            <td class="py-2 ps-3 text-right tabular-nums font-semibold">{{ $fmt($row->total) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-gray-500">Tidak ada piutang yang belum lunas.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($aging['rows']->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 dark:border-white/20 font-semibold">
                            <td class="py-2 pe-4">Total</td>
                            <td class="py-2 px-3 text-right tabular-nums">{{ $fmt($aging['totals']['current_amt']) }}</td>
                            <td class="py-2 px-3 text-right tabular-nums">{{ $fmt($aging['totals']['b1']) }}</td>
                            <td class="py-2 px-3 text-right tabular-nums">{{ $fmt($aging['totals']['b2']) }}</td>
                            <td class="py-2 px-3 text-right tabular-nums">{{ $fmt($aging['totals']['b3']) }}</td>
                            <td class="py-2 px-3 text-right tabular-nums">{{ $fmt($aging['totals']['b4']) }}</td>
                            <td class="py-2 ps-3 text-right tabular-nums">{{ $fmt($aging['totals']['total']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
