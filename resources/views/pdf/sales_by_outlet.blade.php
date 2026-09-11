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
        tr.total td { font-weight: bold; border-top: 2px solid #1f2937; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Penjualan Outlet</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <table>
        <thead>
            <tr>
                <th>Outlet</th>
                <th>Transaksi</th>
                <th>Penjualan (bersih)</th>
                <th>Penjualan %</th>
                <th>Pengembalian</th>
                <th>Produk</th>
                <th>Produk %</th>
                <th>Rata-rata/Transaksi</th>
                <th>Piutang</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['store']->name }}</td>
                    <td>{{ number_format($row['count'], 0, ',', '.') }}</td>
                    <td>{{ $rupiah($row['revenue']) }}</td>
                    <td>{{ number_format($row['revenuePct'], 1, ',', '.') }}%</td>
                    <td>{{ $row['refund'] > 0 ? '(' . $rupiah($row['refund']) . ')' : '-' }}</td>
                    <td>{{ number_format($row['products'], 0, ',', '.') }}</td>
                    <td>{{ number_format($row['productsPct'], 1, ',', '.') }}%</td>
                    <td>{{ $rupiah($row['avg']) }}</td>
                    <td>{{ $row['outstanding'] > 0 ? $rupiah($row['outstanding']) : '-' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total</td>
                <td>{{ number_format($result['totalCount'], 0, ',', '.') }}</td>
                <td>{{ $rupiah($result['totalRevenue']) }}</td>
                <td colspan="2"></td>
                <td>{{ number_format($result['totalProducts'], 0, ',', '.') }}</td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
