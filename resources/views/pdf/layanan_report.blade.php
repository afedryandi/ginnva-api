<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        h2 { font-size: 12px; text-transform: uppercase; margin: 16px 0 6px; color: #6b7280; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .refund { color: #991b1b; font-size: 11px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; }
        td.value, th.value { text-align: right; }
        tr.total td { font-weight: bold; border-top: 2px solid #1f2937; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
        $labels = \App\Filament\Pages\LayananReport::TYPE_LABELS;
        $typeKeys = collect(array_keys($labels))->filter(fn ($key) => $key !== 'lainnya' || $result['byType']['lainnya']['count'] > 0)->values();
        $storeTypeKeys = $typeKeys->filter(fn ($key) => $key !== 'lainnya' || collect($result['byStore'])->sum('types.lainnya') > 0)->values();
    @endphp

    <h1>{{ $title }}</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    @if ($result['refund'] > 0)
        <div class="refund">Setelah pengembalian {{ $rupiah($result['refund']) }} (kotor {{ $rupiah($result['grossRevenue']) }})</div>
    @endif

    <table style="width: 60%">
        <tr><td>Jumlah Transaksi</td><td class="value">{{ number_format($result['totalCount'], 0, ',', '.') }}</td></tr>
        <tr class="total"><td>Total Pendapatan (bersih)</td><td class="value">{{ $rupiah($result['totalRevenue']) }}</td></tr>
        <tr><td>Rata-rata per Transaksi</td><td class="value">{{ $rupiah($result['avgRevenue']) }}</td></tr>
    </table>

    <h2>Per Jenis Layanan</h2>
    <table>
        <tr>
            <th>Jenis</th><th class="value">Jumlah</th><th class="value">Jumlah %</th>
            <th class="value">Pendapatan</th><th class="value">Pendapatan %</th><th class="value">Rata-rata</th>
            <th class="value">Pengembalian</th><th class="value">Bersih</th>
        </tr>
        @foreach ($typeKeys as $key)
            @php $row = $result['byType'][$key]; @endphp
            <tr>
                <td>{{ $labels[$key] }}</td>
                <td class="value">{{ $row['count'] }}</td>
                <td class="value">{{ number_format($row['countPct'], 1) }}%</td>
                <td class="value">{{ $rupiah($row['revenue']) }}</td>
                <td class="value">{{ number_format($row['revenuePct'], 1) }}%</td>
                <td class="value">{{ $row['count'] > 0 ? $rupiah($row['avg']) : '-' }}</td>
                <td class="value">{{ $row['refund'] > 0 ? '(' . $rupiah($row['refund']) . ')' : '-' }}</td>
                <td class="value">{{ $rupiah($row['net']) }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td>Total</td>
            <td class="value">{{ $result['slotCount'] }}</td>
            <td></td>
            <td class="value">{{ $rupiah($result['grossRevenue']) }}</td>
            <td></td>
            <td></td>
            <td class="value">{{ $result['refund'] > 0 ? '(' . $rupiah($result['refund']) . ')' : '-' }}</td>
            <td class="value">{{ $rupiah($result['totalRevenue']) }}</td>
        </tr>
    </table>

    <h2>Per Toko</h2>
    <table>
        <tr>
            <th>Toko</th><th class="value">Jumlah</th>
            @foreach ($storeTypeKeys as $key)
                <th class="value">{{ $labels[$key] }}</th>
            @endforeach
            <th class="value">Pendapatan</th><th class="value">Pengembalian</th><th class="value">Bersih</th>
        </tr>
        @forelse ($result['byStore'] as $storeName => $row)
            <tr>
                <td>{{ $storeName }}</td>
                <td class="value">{{ $row['count'] }}</td>
                @foreach ($storeTypeKeys as $key)
                    <td class="value">{{ $row['types'][$key] > 0 ? $rupiah($row['types'][$key]) : '-' }}</td>
                @endforeach
                <td class="value">{{ $rupiah($row['revenue']) }}</td>
                <td class="value">{{ $row['refund'] > 0 ? '(' . $rupiah($row['refund']) . ')' : '-' }}</td>
                <td class="value">{{ $rupiah($row['net']) }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ 5 + $storeTypeKeys->count() }}">Belum ada data pada rentang ini.</td></tr>
        @endforelse
        @if (count($result['byStore']) > 0)
            <tr class="total">
                <td>Total</td>
                <td class="value">{{ $result['totalCount'] }}</td>
                @foreach ($storeTypeKeys as $key)
                    <td class="value">{{ $rupiah(collect($result['byStore'])->sum('types.' . $key)) }}</td>
                @endforeach
                <td class="value">{{ $rupiah($result['grossRevenue']) }}</td>
                <td class="value">{{ $result['refund'] > 0 ? '(' . $rupiah($result['refund']) . ')' : '-' }}</td>
                <td class="value">{{ $rupiah($result['totalRevenue']) }}</td>
            </tr>
        @endif
    </table>
    <p style="font-size: 9px; color: #9ca3af;">Transaksi berjenis ganda dibagi menurut rincian harga dan terhitung di tiap jenisnya.</p>
</body>
</html>
