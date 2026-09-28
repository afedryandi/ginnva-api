<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-report-notices :notices="$this->getNotices()" />

    @php
        $result = $this->getResult();
        $groups = $this->getGroupedRows($result);
        $labels = \App\Services\FinancialStatementService::TYPE_LABELS;
        $hasPeriod = $result['has_period'] ?? false;
        $shown = $groups->sum(fn ($g) => $g->count());
        $rupiah = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
        $cols = $hasPeriod ? 6 : 5;
    @endphp

    <x-filament::section>
        <x-slot name="heading">Neraca Saldo</x-slot>
        <x-slot name="description">
            @if ($hasPeriod)
                Saldo Awal (sebelum {{ $result['from']->format('d M Y') }}), mutasi periode, dan saldo akhir per akun — dari Jurnal Umum berstatus posted saja.
            @else
                Saldo tiap akun sampai tanggal yang dipilih — dari Jurnal Umum berstatus posted saja. Isi "Dari Tanggal" untuk melihat Saldo Awal dan Mutasi periode.
            @endif
            Klik nama akun untuk melihat Buku Besar-nya.
        </x-slot>

        @if ($result['reset_profit_loss'] ?? false)
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                Akun Pendapatan/Beban dihitung sejak 1 Januari {{ $result['as_of']->year }} (sama dengan Laporan Laba Rugi tahun ini).
                Laba tahun-tahun sebelumnya yang belum ditutup ditampilkan pada baris "Laba Ditahan Tahun Sebelumnya" supaya total tetap seimbang.
            </p>
        @endif

        @if ($result['rows']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada jurnal posted sampai tanggal ini.</p>
        @else
            @if ($shown < $result['rows']->count())
                <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                    Menampilkan {{ $shown }} dari {{ $result['rows']->count() }} akun (filter pencarian/saldo nol). Total di bawah tetap seluruh akun.
                </p>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">Kode</th>
                            <th class="py-2 pr-4">Nama Akun</th>
                            @if ($hasPeriod)
                                <th class="py-2 pr-4 text-right">Saldo Awal</th>
                                <th class="py-2 pr-4 text-right">Mutasi Debit</th>
                                <th class="py-2 pr-4 text-right">Mutasi Kredit</th>
                                <th class="py-2 pr-4 text-right">Saldo Akhir</th>
                            @else
                                <th class="py-2 pr-4 text-right">Debit</th>
                                <th class="py-2 pr-4 text-right">Kredit</th>
                                <th class="py-2 pr-4 text-right">Saldo</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($groups as $type => $items)
                            <tr class="bg-gray-50 dark:bg-white/5">
                                <td class="py-1.5 pr-4 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300" colspan="{{ $cols }}">{{ $labels[$type] ?? $type }}</td>
                            </tr>
                            @foreach ($items as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['account']->code }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($row['account']->id)
                                            <a href="{{ $this->ledgerUrl($row['account']->id) }}" class="hover:underline" title="Lihat Buku Besar akun ini">{{ $row['account']->name }}</a>
                                        @else
                                            {{ $row['account']->name }}
                                        @endif
                                        @if ($row['account']->is_contra)
                                            <span class="text-xs text-gray-400">(pengurang)</span>
                                        @endif
                                    </td>
                                    @if ($hasPeriod)
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($row['opening_balance']) }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $row['period_debit'] > 0 ? $rupiah($row['period_debit']) : '—' }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $row['period_credit'] > 0 ? $rupiah($row['period_credit']) : '—' }}</td>
                                    @else
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $row['debit'] > 0 ? $rupiah($row['debit']) : '—' }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $row['credit'] > 0 ? $rupiah($row['credit']) : '—' }}</td>
                                    @endif
                                    <td class="py-2 pr-4 text-right font-semibold tabular-nums">{{ $rupiah($row['balance']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="text-xs italic text-gray-500 dark:text-gray-400">
                                <td class="py-1.5 pr-4" colspan="2">Subtotal {{ $labels[$type] ?? $type }}</td>
                                @if ($hasPeriod)
                                    <td class="py-1.5 pr-4 text-right tabular-nums">{{ $rupiah($items->sum('opening_balance')) }}</td>
                                    <td class="py-1.5 pr-4 text-right tabular-nums">{{ $rupiah($items->sum('period_debit')) }}</td>
                                    <td class="py-1.5 pr-4 text-right tabular-nums">{{ $rupiah($items->sum('period_credit')) }}</td>
                                @else
                                    <td class="py-1.5 pr-4 text-right tabular-nums">{{ $rupiah($items->sum('debit')) }}</td>
                                    <td class="py-1.5 pr-4 text-right tabular-nums">{{ $rupiah($items->sum('credit')) }}</td>
                                @endif
                                <td class="py-1.5 pr-4 text-right tabular-nums">{{ $rupiah($items->sum('balance')) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="py-4 text-sm text-gray-500 dark:text-gray-400" colspan="{{ $cols }}">Tidak ada akun yang cocok dengan pencarian.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-bold dark:border-white/20">
                            <td class="py-2 pr-4" colspan="2">Total (seluruh akun)</td>
                            @if ($hasPeriod)
                                <td class="py-2 pr-4"></td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($result['rows']->sum('period_debit')) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($result['rows']->sum('period_credit')) }}</td>
                                <td class="py-2 pr-4"></td>
                            @else
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($result['total_debit']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($result['total_credit']) }}</td>
                                <td class="py-2 pr-4"></td>
                            @endif
                        </tr>
                        @if ($hasPeriod)
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <td class="pb-2 pr-4" colspan="{{ $cols }}">Total Debit/Kredit kumulatif s.d. tanggal ini: {{ $rupiah($result['total_debit']) }} / {{ $rupiah($result['total_credit']) }}</td>
                            </tr>
                        @endif
                    </tfoot>
                </table>
            </div>

            @if (! $result['is_balanced'])
                <div class="mt-4 rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-300">
                    Total debit dan kredit tidak sama — seharusnya tidak mungkin terjadi kalau semua jurnal posted lewat JournalEntryService. Segera periksa.
                </div>
            @else
                <div class="mt-4 rounded-lg border border-success-300 bg-success-50 p-3 text-sm text-success-700 dark:border-success-700 dark:bg-success-950 dark:text-success-300">
                    ✓ Seimbang — total debit sama dengan total kredit.
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
