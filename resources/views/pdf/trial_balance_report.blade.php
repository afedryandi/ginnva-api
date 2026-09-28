<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td.value, th.value { text-align: right; }
        tfoot td { font-weight: bold; border-top: 2px solid #9ca3af; border-bottom: none; }
        .warning { margin-top: 12px; color: #991b1b; font-weight: bold; }
        .note { margin-top: 10px; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
        $hasPeriod = $result['has_period'] ?? false;
    @endphp

    <h1>Neraca Saldo</h1>
    <div class="period">
        @if ($hasPeriod)
            Periode: {{ $result['from']->format('d M Y') }} s.d. {{ $result['as_of']->format('d M Y') }}
        @else
            Per Tanggal: {{ $result['as_of']->format('d M Y') }}
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Nama Akun</th>
                @if ($hasPeriod)
                    <th class="value">Saldo Awal</th>
                    <th class="value">Mutasi Debit</th>
                    <th class="value">Mutasi Kredit</th>
                    <th class="value">Saldo Akhir</th>
                @else
                    <th class="value">Debit</th>
                    <th class="value">Kredit</th>
                    <th class="value">Saldo</th>
                @endif
            </tr>
        </thead>
        @php
            $labels = \App\Services\FinancialStatementService::TYPE_LABELS;
            $grouped = collect($result['rows'])->groupBy(fn ($r) => $r['account']->type)
                ->sortBy(fn ($g, $t) => ($p = array_search($t, array_keys($labels), true)) === false ? 99 : $p);
            $cols = $hasPeriod ? 6 : 5;
        @endphp
        <tbody>
            @foreach ($grouped as $type => $items)
                <tr>
                    <td colspan="{{ $cols }}" style="background:#f9fafb;font-weight:bold;">{{ $labels[$type] ?? $type }}</td>
                </tr>
            @foreach ($items as $row)
                <tr>
                    <td>{{ $row['account']->code }}</td>
                    <td>{{ $row['account']->name }}@if ($row['account']->is_contra) (pengurang)@endif</td>
                    @if ($hasPeriod)
                        <td class="value">{{ $rupiah($row['opening_balance']) }}</td>
                        <td class="value">{{ $row['period_debit'] > 0 ? $rupiah($row['period_debit']) : '-' }}</td>
                        <td class="value">{{ $row['period_credit'] > 0 ? $rupiah($row['period_credit']) : '-' }}</td>
                    @else
                        <td class="value">{{ $row['debit'] > 0 ? $rupiah($row['debit']) : '-' }}</td>
                        <td class="value">{{ $row['credit'] > 0 ? $rupiah($row['credit']) : '-' }}</td>
                    @endif
                    <td class="value">{{ $rupiah($row['balance']) }}</td>
                </tr>
            @endforeach
                <tr style="font-style:italic;">
                    <td colspan="2">Subtotal {{ $labels[$type] ?? $type }}</td>
                    @if ($hasPeriod)
                        <td class="value">{{ $rupiah($items->sum('opening_balance')) }}</td>
                        <td class="value">{{ $rupiah($items->sum('period_debit')) }}</td>
                        <td class="value">{{ $rupiah($items->sum('period_credit')) }}</td>
                    @else
                        <td class="value">{{ $rupiah($items->sum('debit')) }}</td>
                        <td class="value">{{ $rupiah($items->sum('credit')) }}</td>
                    @endif
                    <td class="value">{{ $rupiah($items->sum('balance')) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                @if ($hasPeriod)
                    <td></td>
                    <td class="value">{{ $rupiah(collect($result['rows'])->sum('period_debit')) }}</td>
                    <td class="value">{{ $rupiah(collect($result['rows'])->sum('period_credit')) }}</td>
                    <td></td>
                @else
                    <td class="value">{{ $rupiah($result['total_debit']) }}</td>
                    <td class="value">{{ $rupiah($result['total_credit']) }}</td>
                    <td></td>
                @endif
            </tr>
        </tfoot>
    </table>

    @if ($result['reset_profit_loss'] ?? false)
        <div class="note">
            Akun Pendapatan/Beban dihitung sejak 1 Januari {{ $result['as_of']->year }}; laba tahun-tahun sebelumnya yang belum ditutup ditampilkan pada baris "Laba Ditahan Tahun Sebelumnya".
        </div>
    @endif

    @if (! ($result['is_balanced'] ?? true))
        <div class="warning">Total debit dan kredit tidak sama — segera periksa jurnal.</div>
    @endif
    @include('pdf.partials.report-footer')
</body>
</html>
