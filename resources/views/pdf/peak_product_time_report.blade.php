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
    </style>
</head>
<body>
    <h1>Waktu Teramai Produk</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    @if ($result['unassignedCount'] > 0)
        <p>{{ $result['unassignedCount'] }} dari {{ $result['totalCount'] }} transaksi belum diisi varian produk (SKU) spesifiknya.</p>
    @endif

    <table>
        <tr>
            <th>Produk</th><th>Hari</th><th class="value">Jumlah</th><th class="value">Jumlah (%)</th>
            <th class="value">Penjualan (Rp)</th><th class="value">Penjualan (%)</th>
        </tr>
        @forelse ($result['rows'] as $row)
            <tr>
                <td>{{ $row['product'] ? "{$row['product']->sku} - {$row['product']->name}" : 'Belum Diisi SKU' }}</td>
                <td>{{ $row['dayName'] }}</td>
                <td class="value">{{ number_format($row['count'], 0, ',', '.') }}</td>
                <td class="value">{{ number_format($row['countPct'], 1, ',', '.') }}%</td>
                <td class="value">{{ number_format($row['revenue'], 0, ',', '.') }}</td>
                <td class="value">{{ number_format($row['revenuePct'], 1, ',', '.') }}%</td>
            </tr>
        @empty
            <tr><td colspan="6">Belum ada data pada rentang ini.</td></tr>
        @endforelse
    </table>
</body>
</html>
