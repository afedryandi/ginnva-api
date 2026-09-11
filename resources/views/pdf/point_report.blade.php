<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .summary { margin-bottom: 16px; font-size: 11px; color: #374151; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: right; }
        td:first-child, th:first-child { text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Laporan Poin</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        Total Poin Didapatkan: +{{ number_format($result['totalEarned'], 0, ',', '.') }} —
        Total Poin Ditukar: -{{ number_format($result['totalSpent'], 0, ',', '.') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Poin Didapat</th>
                <th>Transaksi Dapat Poin</th>
                <th>Poin Didapat (Rp)</th>
                <th>Poin Ditukar</th>
                <th>Transaksi Tukar Poin</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ number_format($row['earned'], 0, ',', '.') }}</td>
                    <td>{{ number_format($row['earnCount'], 0, ',', '.') }}</td>
                    <td>{{ $row['earnRp'] > 0 ? $rupiah($row['earnRp']) : '-' }}</td>
                    <td>{{ number_format($row['spent'], 0, ',', '.') }}</td>
                    <td>{{ number_format($row['spentCount'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
