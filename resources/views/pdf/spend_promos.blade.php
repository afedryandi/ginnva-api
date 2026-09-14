<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.value, th.value { text-align: right; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
    </style>
</head>
<body>
    <h1>Daftar Promo Total Pembelian</h1>

    <table>
        <tr>
            <th>Nama</th><th class="value">Minimal Pembelian</th><th class="value">Potongan</th>
            <th>Mulai Berlaku</th><th>Berakhir</th><th class="value">Dipakai (Booking)</th><th>Status</th>
        </tr>
        @forelse ($promos as $promo)
            <tr>
                <td>{{ $promo->name }}</td>
                <td class="value">{{ number_format((float) $promo->min_purchase_amount, 0, ',', '.') }}</td>
                <td class="value">{{ number_format((float) $promo->discount_amount, 0, ',', '.') }}</td>
                <td>{{ $promo->starts_on?->format('d M Y') ?? '-' }}</td>
                <td>{{ $promo->ends_on?->format('d M Y') ?? '-' }}</td>
                <td class="value">{{ $promo->bookings_count }}</td>
                <td>{{ ! $promo->is_active ? 'Nonaktif' : ($promo->isRunning() ? 'Berjalan' : 'Terjadwal / Lewat') }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Belum ada promo.</td></tr>
        @endforelse
    </table>
</body>
</html>
