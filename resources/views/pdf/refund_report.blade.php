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
        $financeOn = (bool) config('features.finance');
    @endphp

    <h1>Laporan Refund</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        {{ number_format($result['totalCount'], 0, ',', '.') }} pengembalian — total {{ $rupiah($result['totalAmount']) }}
        (refund penjualan {{ $rupiah($result['salesAmount']) }}, refund DP {{ $rupiah($result['dpAmount']) }})
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Refund</th>
                <th>Tanggal</th>
                <th>Jenis</th>
                <th>No. Booking</th>
                <th>Pelanggan</th>
                <th>Toko</th>
                <th>Metode</th>
                <th>Diproses Oleh</th>
                <th>Disetujui Oleh</th>
                @if ($financeOn)
                    <th>No. Jurnal</th>
                @endif
                <th>Alasan</th>
                <th class="value">Nominal</th>
                <th class="value">Sisa Setelah Refund</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                @php $booking = $row['booking']; @endphp
                <tr>
                    <td>{{ $row['number'] ?? '-' }}</td>
                    <td>{{ $row['date']->format('d M Y H:i') }}</td>
                    <td>{{ $row['kind'] }}</td>
                    <td>{{ $booking?->booking_number ?? '-' }}</td>
                    <td>{{ $booking?->display_customer_name ?? '-' }}</td>
                    <td>{{ $booking?->store?->name ?? '-' }}</td>
                    <td>{{ $row['method_label'] }}</td>
                    <td>{{ $row['by'] ?? '-' }}</td>
                    <td>{{ $row['approved_by'] ?? '-' }}</td>
                    @if ($financeOn)
                        <td>{{ $row['journal'] ?? '-' }}</td>
                    @endif
                    <td>{{ $row['reason'] ?: '-' }}</td>
                    <td class="value">{{ $rupiah($row['amount']) }}</td>
                    <td class="value">{{ $row['remaining'] === null ? '-' : ($row['remaining'] <= 0 ? 'Penuh' : $rupiah($row['remaining'])) }}</td>
                </tr>
            @endforeach
        </tbody>
        @if ($result['rows']->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="{{ $financeOn ? 11 : 10 }}">Total</td>
                    <td class="value">{{ $rupiah($result['totalAmount']) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
