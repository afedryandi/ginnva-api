<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 8px; }
        td.value, th.value { text-align: right; }
        .summary { display: table; width: 100%; margin-bottom: 14px; }
        .summary .item { display: table-cell; padding: 6px 10px; border: 1px solid #e5e7eb; }
        .summary .label { font-size: 8px; color: #6b7280; text-transform: uppercase; }
        .summary .amount { font-size: 12px; font-weight: bold; }
        .badge { padding: 1px 6px; border-radius: 3px; font-size: 8px; }
        .badge-lunas { background: #dcfce7; color: #15803d; }
        .badge-belum { background: #fef9c3; color: #a16207; }
        .badge-void { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Detail Penjualan</h1>
    <div class="period">Dicetak {{ now()->format('d M Y H:i') }} · {{ $bookings->count() }} baris</div>

    <div class="summary">
        <div class="item"><div class="label">Total Invoice</div><div class="amount">{{ $rupiah($stats['total_revenue']) }}</div><div>{{ $stats['total_count'] }} invoice</div></div>
        <div class="item"><div class="label">Lunas</div><div class="amount">{{ $rupiah($stats['lunas_amount']) }}</div><div>{{ $stats['lunas_count'] }} invoice</div></div>
        <div class="item"><div class="label">Belum Lunas</div><div class="amount">{{ $rupiah($stats['belum_lunas_amount']) }}</div><div>{{ $stats['belum_lunas_count'] }} invoice</div></div>
        <div class="item"><div class="label">Void</div><div class="amount">{{ $rupiah($stats['void_amount']) }}</div><div>{{ $stats['void_count'] }} invoice</div></div>
        <div class="item"><div class="label">Total Diterima</div><div class="amount">{{ $rupiah($stats['total_received']) }}</div></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Invoice</th>
                <th>Pelanggan</th>
                <th>Toko</th>
                <th>Produk</th>
                <th class="value">Nilai Transaksi</th>
                <th class="value">Diterima</th>
                <th class="value">Sisa</th>
                <th>Status</th>
                <th>Waktu Bayar</th>
                <th>No. Jurnal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($bookings as $booking)
                @php
                    $received = $booking->amount_received !== null ? (float) $booking->amount_received : (float) $booking->transaction_amount;
                    $outstanding = max(0, (float) $booking->transaction_amount - $received);
                    $status = $booking->status === 'cancelled' ? 'Void' : ($outstanding > 0.009 ? 'Belum Lunas' : 'Lunas');
                    $badgeClass = match ($status) { 'Lunas' => 'badge-lunas', 'Belum Lunas' => 'badge-belum', default => 'badge-void' };
                @endphp
                <tr>
                    <td>INV/{{ $booking->booking_number }}</td>
                    <td>{{ $booking->customer_name ?? '-' }}</td>
                    <td>{{ $booking->store?->name ?? '-' }}</td>
                    <td>
                        @if ($booking->product_kaca_film && $booking->product_ppf) Kaca Film + PPF
                        @elseif ($booking->product_ppf) PPF
                        @elseif ($booking->product_kaca_film) Kaca Film
                        @else - @endif
                    </td>
                    <td class="value">{{ $rupiah($booking->transaction_amount) }}</td>
                    <td class="value">{{ $rupiah($received) }}</td>
                    <td class="value">{{ $outstanding > 0 ? $rupiah($outstanding) : '-' }}</td>
                    <td><span class="badge {{ $badgeClass }}">{{ $status }}</span></td>
                    <td>{{ optional($booking->journalEntry?->entry_date)->format('d M Y') ?? '-' }}</td>
                    <td>{{ $booking->journalEntry?->entry_number ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="10">Tidak ada data untuk filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Catatan kaki (audit Detail Penjualan 2026-09-29) -- bukan pola laporan Keuangan generik
    (booking selalu punya toko, tidak ada konsep "Pusat/Tanpa Toko" di sini). --}}
    <div style="margin-top: 16px; padding-top: 8px; border-top: 1px solid #999; font-size: 9px; color: #555;">
        Sumber data: booking yang sudah tercatat sebagai pendapatan (ada Jurnal Umum posted). Mengikuti filter yang aktif saat mencetak.<br>
        Dicetak {{ now()->translatedFormat('d M Y H:i') }} oleh {{ auth()->user()?->name ?? '—' }}.
    </div>
</body>
</html>
