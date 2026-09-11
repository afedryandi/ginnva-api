<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        h2 { font-size: 11px; text-transform: uppercase; margin: 16px 0 6px; color: #6b7280; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.value, th.value { text-align: right; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>{{ $title }}</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <table>
        <tr><td>Total Transaksi dengan Promo</td><td class="value">{{ number_format($result['promoTransactionCount'], 0, ',', '.') }}</td></tr>
        <tr><td>Nilai Promo</td><td class="value">({{ $rupiah($result['promoValue']) }})</td></tr>
        <tr><td>Total Penjualan dengan Promo</td><td class="value">{{ $rupiah($result['promoSalesTotal']) }}</td></tr>
    </table>

    <h2>Detail Transaksi Promo</h2>
    <table>
        <tr><th>Tanggal</th><th>Promo</th><th>No. Booking</th><th>Toko</th><th class="value">Nilai</th></tr>
        @forelse ($result['usedClaims'] as $claim)
            <tr>
                <td>{{ optional($claim->used_at)->format('d M Y') }}</td>
                <td>{{ $claim->voucher?->name ?? '-' }}</td>
                <td>{{ $claim->booking?->booking_number ?? '-' }}</td>
                <td>{{ $claim->booking?->store?->name ?? '-' }}</td>
                <td class="value">({{ $rupiah($claim->voucher->discount_amount ?? 0) }})</td>
            </tr>
        @empty
            <tr><td colspan="5">Tidak ada promo dipakai pada rentang ini.</td></tr>
        @endforelse
    </table>

    <h2>Performa Voucher (company-wide)</h2>
    <table>
        <tr><th>Voucher</th><th class="value">Diklaim</th><th class="value">Dipakai</th><th class="value">Sisa Stok</th><th>Status</th></tr>
        @forelse ($result['vouchers'] as $voucher)
            <tr>
                <td>{{ $voucher->name }}</td>
                <td class="value">{{ $voucher->claimed_in_period }}</td>
                <td class="value">{{ $voucher->used_in_period }}</td>
                <td class="value">{{ $voucher->remainingStock() }}</td>
                <td>{{ $voucher->is_active ? 'Aktif' : 'Nonaktif' }}</td>
            </tr>
        @empty
            <tr><td colspan="5">Belum ada voucher.</td></tr>
        @endforelse
    </table>

    <h2>Performa Reward (company-wide)</h2>
    <table>
        <tr><th>Reward</th><th class="value">Ditukar</th><th class="value">Terpenuhi</th><th class="value">Poin Terpakai</th><th class="value">Sisa Stok</th></tr>
        @forelse ($result['rewards'] as $reward)
            <tr>
                <td>{{ $reward->name }}</td>
                <td class="value">{{ $reward->redeemed_in_period }}</td>
                <td class="value">{{ $reward->fulfilled_in_period }}</td>
                <td class="value">{{ number_format($reward->points_spent_in_period ?? 0, 0, ',', '.') }}</td>
                <td class="value">{{ $reward->stock === null ? '∞' : $reward->stock }}</td>
            </tr>
        @empty
            <tr><td colspan="5">Belum ada reward.</td></tr>
        @endforelse
    </table>

    <h2>Poin Loyalti (company-wide, tidak per cabang)</h2>
    <table>
        <tr><td>Poin Customer Diterbitkan</td><td class="value">+{{ number_format($result['points']['issued_customer'], 0, ',', '.') }}</td></tr>
        <tr><td>Poin Customer Dipakai</td><td class="value">-{{ number_format($result['points']['spent_customer'], 0, ',', '.') }}</td></tr>
        <tr><td>Poin Partner Diterbitkan</td><td class="value">+{{ number_format($result['points']['issued_partner'], 0, ',', '.') }}</td></tr>
        <tr><td>Poin Partner Dipakai</td><td class="value">-{{ number_format($result['points']['spent_partner'], 0, ',', '.') }}</td></tr>
        <tr><td>Total Klaim Reward</td><td class="value">{{ number_format($result['totalRedemptions'], 0, ',', '.') }}</td></tr>
    </table>
</body>
</html>
