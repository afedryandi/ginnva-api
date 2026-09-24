<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td.value, th.value { text-align: right; }
        tr.opening td { background: #f9fafb; font-style: italic; font-weight: bold; }
        tr.total td { font-weight: bold; border-top: 2px solid #9ca3af; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Buku Besar</h1>
    <div class="period">{{ $result['account']->display_name }}</div>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>No. Jurnal</th>
                <th>Keterangan</th>
                <th class="value">Debit</th>
                <th class="value">Kredit</th>
                <th class="value">Saldo Berjalan</th>
            </tr>
        </thead>
        <tbody>
            <tr class="opening">
                <td colspan="5">Saldo Awal</td>
                <td class="value">{{ $rupiah($result['opening_balance']) }}</td>
            </tr>

            @forelse ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['entry_date']->format('d M Y') }}</td>
                    <td>{{ $row['entry_number'] }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td class="value">{{ $row['debit'] > 0 ? $rupiah($row['debit']) : '—' }}</td>
                    <td class="value">{{ $row['credit'] > 0 ? $rupiah($row['credit']) : '—' }}</td>
                    <td class="value">{{ $rupiah($row['running_balance']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Tidak ada mutasi di rentang tanggal ini.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="total">
                <td colspan="3">Total Mutasi Periode Ini</td>
                <td class="value">{{ $rupiah($result['total_debit']) }}</td>
                <td class="value">{{ $rupiah($result['total_credit']) }}</td>
                <td class="value">{{ $rupiah($result['closing_balance']) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
