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
        // Diterima dan Piutang hanya ada bila modul Keuangan menyala. HPP dan Laba Kotor dihapus 2026-11-04 (HPP dipegang Accurate).
        $financeOn = (bool) config('features.finance');
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
                @if ($financeOn)
                    <th>Diterima</th>
                    <th>Piutang</th>
                @endif
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
                    @if ($financeOn)
                        <td>{{ $rupiah($row['received']) }}</td>
                        <td>{{ $row['outstanding'] > 0 ? $rupiah($row['outstanding']) : '-' }}</td>
                    @endif
                    <td>{{ number_format($row['products'], 0, ',', '.') }}</td>
                    <td>{{ $row['refund'] > 0 ? '(' . $rupiah($row['refund']) . ')' : '-' }}</td>
                    <td>{{ $row['commission'] > 0 ? $rupiah($row['commission']) : '-' }}{{ $row['hasUnratedJob'] ? ' *' : '' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total</td>
                <td>{{ number_format($result['totalCount'], 0, ',', '.') }}</td>
                <td>{{ $rupiah($result['totalRevenue']) }}</td>
                @if ($financeOn)
                    <td>{{ $rupiah($result['totalReceived']) }}</td>
                    <td>{{ $result['totalOutstanding'] > 0 ? $rupiah($result['totalOutstanding']) : '-' }}</td>
                @endif
                <td>{{ number_format($result['totalProducts'], 0, ',', '.') }}</td>
                <td>{{ $result['totalRefund'] > 0 ? '(' . $rupiah($result['totalRefund']) . ')' : '-' }}</td>
                <td>{{ $result['totalCommission'] > 0 ? $rupiah($result['totalCommission']) : '-' }}</td>
            </tr>
        </tbody>
    </table>

    <p class="footnote">* = ada installer yang komisinya belum diatur (menu Installer) pada periode itu — nominal Komisi belum mencerminkan semua pekerjaan.</p>
    <p class="footnote">Pengembalian dikelompokkan menurut tanggal refund diproses, bukan tanggal penjualannya.</p>
</body>
</html>
