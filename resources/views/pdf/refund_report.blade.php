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

    <h1>Laporan Refund</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        {{ number_format($result['totalCount'], 0, ',', '.') }} refund — total {{ $rupiah($result['totalAmount']) }}
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Refund</th>
                <th>Tanggal</th>
                <th>No. Booking</th>
                <th>Pelanggan</th>
                <th>Toko</th>
                <th>Diproses Oleh</th>
                <th>No. Jurnal</th>
                <th>Alasan</th>
                <th class="value">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['refunds'] as $refund)
                <tr>
                    <td>{{ $refund->refund_number }}</td>
                    <td>{{ $refund->created_at->format('d M Y H:i') }}</td>
                    <td>{{ $refund->booking?->booking_number ?? '-' }}</td>
                    <td>{{ $refund->booking?->customer_name ?? '-' }}</td>
                    <td>{{ $refund->booking?->store?->name ?? '-' }}</td>
                    <td>{{ $refund->creator?->name ?? '-' }}</td>
                    <td>{{ $refund->journalEntry?->entry_number ?? '-' }}</td>
                    <td>{{ $refund->reason ?: '-' }}</td>
                    <td class="value">{{ $rupiah((float) $refund->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
