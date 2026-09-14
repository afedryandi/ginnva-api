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
    <h1>Daftar Voucher Promo</h1>

    <table>
        <tr>
            <th>Nama Kampanye</th><th class="value">Potongan</th><th class="value">Ter-assign</th>
            <th class="value">Total Stok</th><th>Kedaluwarsa</th><th>Aktif</th>
        </tr>
        @forelse ($vouchers as $voucher)
            <tr>
                <td>{{ $voucher->name }}</td>
                <td class="value">{{ number_format((float) $voucher->discount_amount, 0, ',', '.') }}</td>
                <td class="value">{{ $voucher->claimed_count }}</td>
                <td class="value">{{ $voucher->total_stock }}</td>
                <td>{{ $voucher->expires_at?->format('d M Y H:i') ?? 'Tanpa batas waktu' }}</td>
                <td>{{ $voucher->is_active ? 'Ya' : 'Tidak' }}</td>
            </tr>
        @empty
            <tr><td colspan="6">Belum ada voucher.</td></tr>
        @endforelse
    </table>
</body>
</html>
