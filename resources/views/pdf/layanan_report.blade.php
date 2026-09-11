<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        h2 { font-size: 12px; text-transform: uppercase; margin: 16px 0 6px; color: #6b7280; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .refund { color: #991b1b; font-size: 11px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        td, th { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        td.value, th.value { text-align: right; }
        tr.total td { font-weight: bold; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>{{ $title }}</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    @if ($result['refund'] > 0)
        <div class="refund">Setelah pengembalian {{ $rupiah($result['refund']) }} (kotor {{ $rupiah($result['grossRevenue']) }})</div>
    @endif

    <table>
        <tr><td>Jasa Terjual</td><td class="value">{{ number_format($result['totalCount'], 0, ',', '.') }}</td></tr>
        <tr class="total"><td>Total Pendapatan (bersih)</td><td class="value">{{ $rupiah($result['totalRevenue']) }}</td></tr>
        <tr><td>Rata-rata per Jasa</td><td class="value">{{ $rupiah($result['avgRevenue']) }}</td></tr>
    </table>

    <h2>Per Jenis Servis (kotor, belum dikurangi pengembalian)</h2>
    <table>
        <tr>
            <th>Jenis</th><th class="value">Jumlah</th><th class="value">Jumlah %</th>
            <th class="value">Pendapatan</th><th class="value">Pendapatan %</th>
        </tr>
        <tr>
            <td>Kaca Film</td>
            <td class="value">{{ $result['byType']['kaca_film']['count'] }}</td>
            <td class="value">{{ number_format($result['byType']['kaca_film']['countPct'], 1) }}%</td>
            <td class="value">{{ $rupiah($result['byType']['kaca_film']['revenue']) }}</td>
            <td class="value">{{ number_format($result['byType']['kaca_film']['revenuePct'], 1) }}%</td>
        </tr>
        <tr>
            <td>PPF</td>
            <td class="value">{{ $result['byType']['ppf']['count'] }}</td>
            <td class="value">{{ number_format($result['byType']['ppf']['countPct'], 1) }}%</td>
            <td class="value">{{ $rupiah($result['byType']['ppf']['revenue']) }}</td>
            <td class="value">{{ number_format($result['byType']['ppf']['revenuePct'], 1) }}%</td>
        </tr>
    </table>

    <h2>Per Toko</h2>
    <table>
        <tr><th>Toko</th><th class="value">Jumlah</th><th class="value">Pendapatan</th></tr>
        @forelse ($result['byStore'] as $storeName => $row)
            <tr>
                <td>{{ $storeName }}</td>
                <td class="value">{{ $row['count'] }}</td>
                <td class="value">{{ $rupiah($row['revenue']) }}</td>
            </tr>
        @empty
            <tr><td colspan="3">Belum ada data pada rentang ini.</td></tr>
        @endforelse
    </table>
</body>
</html>
