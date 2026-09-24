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
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Neraca Saldo</h1>
    <div class="period">Per Tanggal: {{ $result['as_of']->format('d M Y') }}</div>

    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Nama Akun</th>
                <th class="value">Debit</th>
                <th class="value">Kredit</th>
                <th class="value">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['account']->code }}</td>
                    <td>{{ $row['account']->name }}</td>
                    <td class="value">{{ $row['debit'] > 0 ? $rupiah($row['debit']) : '-' }}</td>
                    <td class="value">{{ $row['credit'] > 0 ? $rupiah($row['credit']) : '-' }}</td>
                    <td class="value">{{ $rupiah($row['balance']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                <td class="value">{{ $rupiah($result['total_debit']) }}</td>
                <td class="value">{{ $rupiah($result['total_credit']) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    @if (round($result['total_debit'], 2) !== round($result['total_credit'], 2))
        <div class="warning">Total debit dan kredit tidak sama — segera periksa jurnal.</div>
    @endif
</body>
</html>
