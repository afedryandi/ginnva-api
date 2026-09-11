<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .summary { margin-bottom: 16px; font-size: 11px; color: #374151; }
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

    <h1>Laporan Reservasi</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        Dibuat: {{ number_format($result['totalCreated'], 0, ',', '.') }} —
        Selesai: {{ number_format($result['totalCompleted'], 0, ',', '.') }} —
        Dibatalkan: {{ number_format($result['totalCancelled'], 0, ',', '.') }}
        ({{ number_format($result['cancellationRate'], 1, ',', '.') }}%)
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Booking</th>
                <th>Tanggal Buat</th>
                <th>Tanggal Diinginkan</th>
                <th>Durasi</th>
                <th>Pelanggan</th>
                <th>Toko</th>
                <th>Layanan</th>
                <th>Teknisi</th>
                <th class="value">Total Tagihan</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['bookings'] as $booking)
                <tr>
                    <td>{{ $booking->booking_number }}</td>
                    <td>{{ optional($booking->created_at)->format('d M Y') }}</td>
                    <td>{{ $booking->preferred_date?->format('d M Y') }}</td>
                    <td>{{ $booking->duration_days }} hari</td>
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
                    <td>{{ $booking->installers->pluck('name')->join(', ') ?: '-' }}</td>
                    <td class="value">{{ $booking->transaction_amount ? $rupiah($booking->transaction_amount) : '-' }}</td>
                    <td>{{ $booking->status === 'confirmed' ? 'Terkonfirmasi' : 'Menunggu Approval' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
