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
    <h1>Katalog Reward</h1>

    <table>
        <tr><th>Nama</th><th class="value">Harga Poin</th><th class="value">Stok</th><th>Aktif</th></tr>
        @forelse ($rewards as $reward)
            <tr>
                <td>{{ $reward->name }}</td>
                <td class="value">{{ number_format($reward->points_cost, 0, ',', '.') }}</td>
                <td class="value">{{ $reward->stock ?? 'Tanpa batas' }}</td>
                <td>{{ $reward->is_active ? 'Ya' : 'Tidak' }}</td>
            </tr>
        @empty
            <tr><td colspan="4">Belum ada reward.</td></tr>
        @endforelse
    </table>
</body>
</html>
