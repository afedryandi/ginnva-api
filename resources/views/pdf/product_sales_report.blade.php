<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .refund { color: #991b1b; font-size: 11px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: right; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td:first-child, td:nth-child(2), td:nth-child(3), th:first-child, th:nth-child(2), th:nth-child(3) { text-align: left; }
        tr.total td { font-weight: bold; border-top: 2px solid #1f2937; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Penjualan Produk</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    @if ($result['totalRefundAmount'] > 0)
        <div class="refund">Total Penjualan (bersih) {{ $rupiah($result['totalRevenue']) }} — setelah pengembalian {{ $rupiah($result['totalRefundAmount']) }} (kotor {{ $rupiah($result['grossRevenue']) }})</div>
    @endif

    <table>
        <thead>
            <tr>
                <th>Produk</th>
                <th>SKU</th>
                <th>Jenis Produk</th>
                <th>Jumlah</th>
                <th>Jumlah %</th>
                <th>Penjualan</th>
                <th>Penjualan %</th>
                <th>Jumlah Refund</th>
                <th>Refund</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['sku'] }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td>{{ number_format($row['count'], 0, ',', '.') }}</td>
                    <td>{{ number_format($row['countPct'], 1, ',', '.') }}%</td>
                    <td>{{ $rupiah($row['revenue']) }}</td>
                    <td>{{ number_format($row['revenuePct'], 1, ',', '.') }}%</td>
                    <td>{{ $row['refundCount'] > 0 ? $row['refundCount'] : '-' }}</td>
                    <td>{{ $row['refundAmount'] > 0 ? '(' . $rupiah($row['refundAmount']) . ')' : '-' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">Total</td>
                <td>{{ number_format($result['totalCount'], 0, ',', '.') }}</td>
                <td></td>
                <td>{{ $rupiah($result['grossRevenue']) }}</td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
