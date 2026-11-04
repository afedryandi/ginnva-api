<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .summary { margin-bottom: 16px; color: #991b1b; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 5px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 8px; }
        td.value, th.value { text-align: right; }
        tfoot td { font-weight: bold; border-top: 2px solid #6b7280; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Laporan Pembatalan</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        {{ number_format($result['totalCount'], 0, ',', '.') }} booking dibatalkan
        ({{ number_format($result['noShowCount'], 0, ',', '.') }} pelanggan tidak datang) —
        potensi pendapatan hilang {{ $rupiah($result['totalLostRevenue']) }}
        @if ($result['openDpTotal'] > 0)
            — DP belum dikembalikan {{ $rupiah($result['openDpTotal']) }}
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Booking</th>
                <th>Tanggal Order</th>
                <th>Tanggal Dibatalkan</th>
                <th>Jenis</th>
                <th>Pelanggan</th>
                <th>Toko</th>
                <th>Layanan</th>
                <th>Dibatalkan Oleh</th>
                <th>Alasan</th>
                <th class="value">Nilai Transaksi</th>
                <th class="value">DP Belum Dikembalikan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                @php $booking = $row['booking']; @endphp
                <tr>
                    <td>{{ $booking->booking_number }}</td>
                    <td>{{ optional($booking->created_at)->format('d M Y H:i') }}</td>
                    <td>{{ $row['cancelled_at']->format('d M Y H:i') }}</td>
                    <td>{{ $row['kind'] }}</td>
                    <td>{{ $booking->display_customer_name }}</td>
                    <td>{{ $booking->store?->name ?? '-' }}</td>
                    <td>{{ $booking->salesProductLabel() ?? '-' }}</td>
                    <td>{{ $row['by'] }}</td>
                    <td>{{ $row['reason'] ?? '-' }}</td>
                    <td class="value">{{ $row['amount'] > 0 ? $rupiah($row['amount']) : '-' }}</td>
                    <td class="value">{{ $row['dp_open'] > 0 ? $rupiah($row['dp_open']) : '-' }}</td>
                </tr>
            @endforeach
        </tbody>
        @if ($result['rows']->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="9">Total</td>
                    <td class="value">{{ $rupiah($result['totalLostRevenue']) }}</td>
                    <td class="value">{{ $rupiah($result['openDpTotal']) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
