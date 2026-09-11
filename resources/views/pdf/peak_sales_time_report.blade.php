<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.value, th.value { text-align: right; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        .summary { margin-bottom: 16px; }
        .summary td { border: none; padding: 2px 6px; }
    </style>
</head>
<body>
    <h1>Waktu Teramai Penjualan</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <table class="summary">
        <tr>
            <td>Total Penjualan: <strong>Rp{{ number_format($result['totalRevenue'], 0, ',', '.') }}</strong></td>
            <td>Transaksi: <strong>{{ number_format($result['totalCount'], 0, ',', '.') }}</strong></td>
        </tr>
        <tr>
            <td>Produk: <strong>{{ number_format($result['totalProducts'], 0, ',', '.') }}</strong></td>
            <td>Pelanggan: <strong>{{ number_format($result['totalCustomers'], 0, ',', '.') }}</strong></td>
        </tr>
    </table>

    <table>
        <tr>
            <th>Hari</th><th class="value">Penjualan (Rp)</th><th class="value">Penjualan (%)</th>
            <th class="value">Transaksi</th><th class="value">Transaksi (%)</th>
            <th class="value">Produk</th><th class="value">Produk (%)</th><th class="value">Pelanggan</th>
        </tr>
        @foreach ($result['rows'] as $row)
            <tr>
                <td>{{ $row['dayName'] }}</td>
                <td class="value">{{ number_format($row['revenue'], 0, ',', '.') }}</td>
                <td class="value">{{ number_format($row['revenuePct'], 1, ',', '.') }}%</td>
                <td class="value">{{ $row['count'] }}</td>
                <td class="value">{{ number_format($row['countPct'], 1, ',', '.') }}%</td>
                <td class="value">{{ $row['products'] }}</td>
                <td class="value">{{ number_format($row['productsPct'], 1, ',', '.') }}%</td>
                <td class="value">{{ $row['customers'] }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
