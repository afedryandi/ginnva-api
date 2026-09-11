<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: right; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td:first-child, th:first-child { text-align: left; }
        tr.empty td { color: #9ca3af; }
        tr.total td { font-weight: bold; border-top: 2px solid #1f2937; }
        .footnote { margin-top: 10px; font-size: 9px; color: #9ca3af; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Penjualan Per Periode</h1>
    <div class="period">
        Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}
        (dikelompokkan per {{ ucfirst($result['granularity']) }})
    </div>

    <table>
        <thead>
            <tr>
                <th>Periode</th>
                <th>Transaksi</th>
                <th>Penjualan</th>
                <th>Diterima</th>
                <th>Piutang</th>
                <th>Produk</th>
                <th>Pengembalian</th>
                <th>Komisi</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                <tr @class(['empty' => $row['count'] === 0])>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ number_format($row['count'], 0, ',', '.') }}</td>
                    <td>{{ $rupiah($row['revenue']) }}</td>
                    <td>{{ $rupiah($row['received']) }}</td>
                    <td>{{ $row['outstanding'] > 0 ? $rupiah($row['outstanding']) : '-' }}</td>
                    <td>{{ number_format($row['products'], 0, ',', '.') }}</td>
                    <td>{{ $row['refund'] > 0 ? '(' . $rupiah($row['refund']) . ')' : '-' }}</td>
                    <td>{{ $row['commission'] > 0 ? $rupiah($row['commission']) : '-' }}{{ $row['hasUnratedJob'] ? ' *' : '' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total</td>
                <td>{{ number_format($result['totalCount'], 0, ',', '.') }}</td>
                <td>{{ $rupiah($result['totalRevenue']) }}</td>
                <td colspan="2"></td>
                <td>{{ number_format($result['totalProducts'], 0, ',', '.') }}</td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>

    <p class="footnote">* = ada teknisi yang komisinya belum diatur (menu Teknisi) pada periode itu — nominal Komisi belum mencerminkan semua pekerjaan.</p>
</body>
</html>
