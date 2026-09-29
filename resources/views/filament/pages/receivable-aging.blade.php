<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $aging = $this->getAging();
        $fmt = fn ($n) => (float) $n > 0 ? 'Rp ' . number_format((float) $n, 0, ',', '.') : '—';
    @endphp

    <div class="mb-6 grid grid-cols-2 gap-4 text-sm lg:grid-cols-3">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Jumlah Customer</div>
            <div class="text-lg font-semibold tabular-nums">{{ $aging['customer_count'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Customer Terlambat</div>
            <div class="text-lg font-semibold tabular-nums">{{ $aging['overdue_count'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Porsi &gt; 90 Hari</div>
            <div class="text-lg font-semibold tabular-nums">{{ number_format($aging['over_90_pct'], 1, ',', '.') }}%</div>
        </div>
    </div>

    <x-filament::section>
        <x-slot name="heading">Sisa piutang per customer menurut umur jatuh tempo</x-slot>
        <x-slot name="description">
            Toko: {{ $this->storeLabel() }}. Hanya piutang Belum Diterima / Diterima Sebagian. "Belum jatuh tempo" mencakup piutang tanpa tanggal jatuh tempo. Dihitung per hari ini. Klik nama customer untuk melihat piutangnya di Piutang Usaha.
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
                            <td class="py-2 pe-4 font-medium">
                                <a href="{{ $this->receivableUrl($row->customer_id, $row->customer) }}" class="hover:underline" title="Lihat piutang customer ini">{{ $row->customer }}</a>
                            </td>
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
