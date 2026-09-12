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
    <h1>Daftar Barang Habis Pakai</h1>

    <table>
        <tr>
            <th>Kode</th><th>Nama Barang</th><th>Kategori</th><th class="value">Stok</th>
            <th>Satuan</th><th class="value">Ambang Menipis</th><th class="value">Harga/Satuan</th>
        </tr>
        @forelse ($items as $item)
            <tr>
                <td>{{ $item->code ?? '-' }}</td>
                <td>{{ $item->name }}</td>
                <td>{{ $item->category ?? '-' }}</td>
                <td class="value">{{ number_format((float) $item->current_stock, 2, ',', '.') }}</td>
                <td>{{ $item->unit }}</td>
                <td class="value">{{ $item->reorder_point !== null ? number_format((float) $item->reorder_point, 2, ',', '.') : '-' }}</td>
                <td class="value">{{ $item->unit_cost !== null ? number_format((float) $item->unit_cost, 0, ',', '.') : '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Belum ada barang.</td></tr>
        @endforelse
    </table>
</body>
</html>
