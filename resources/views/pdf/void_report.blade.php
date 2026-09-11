<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .summary { margin-bottom: 16px; color: #991b1b; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td.value, th.value { text-align: right; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Laporan Void</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        {{ number_format($result['totalCount'], 0, ',', '.') }} booking dibatalkan —
        potensi pendapatan hilang {{ $rupiah($result['totalLostRevenue']) }}
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Booking</th>
                <th>Tanggal Order</th>
                <th>Tanggal Dibatalkan</th>
                <th>Pelanggan</th>
                <th>Toko</th>
                <th>Layanan</th>
                <th>Dibatalkan Oleh</th>
                <th class="value">Nilai Transaksi</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['events'] as $event)
                @php $booking = $event->subject; @endphp
                <tr>
                    <td>{{ $booking->booking_number }}</td>
                    <td>{{ optional($booking->created_at)->format('d M Y H:i') }}</td>
                    <td>{{ $event->created_at->format('d M Y H:i') }}</td>
                    <td>{{ $booking->customer_name ?? '-' }}</td>
                    <td>{{ $booking->store?->name ?? '-' }}</td>
                    <td>
                        @if ($booking->product_kaca_film && $booking->product_ppf)
                            Kaca Film + PPF
                        @elseif ($booking->product_ppf)
                            PPF
                        @elseif ($booking->product_kaca_film)
                            Kaca Film
                        @else
                            -
                        @endif
                    </td>
                    <td>{{ $event->causer?->name ?? 'Sistem (otomatis)' }}</td>
                    <td class="value">{{ $booking->transaction_amount > 0 ? $rupiah($booking->transaction_amount) : '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
