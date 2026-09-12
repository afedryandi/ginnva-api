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
    <h1>Daftar Bahan Baku</h1>

    <table>
        <tr>
            <th>Kode</th><th>Nama Bahan</th><th>Kategori</th><th class="value">Stok (Total)</th>
            <th>Satuan</th><th class="value">Ambang Menipis</th><th class="value">Harga/Satuan</th><th>Kedaluwarsa Terdekat</th>
        </tr>
        @forelse ($materials as $material)
            <tr>
                <td>{{ $material->code ?? '-' }}</td>
                <td>{{ $material->name }}</td>
                <td>{{ $material->category ?? '-' }}</td>
                <td class="value">{{ number_format((float) $material->current_stock, 2, ',', '.') }}</td>
                <td>{{ $material->unit }}</td>
                <td class="value">{{ $material->reorder_point !== null ? number_format((float) $material->reorder_point, 2, ',', '.') : '-' }}</td>
                <td class="value">{{ $material->unit_cost !== null ? number_format((float) $material->unit_cost, 0, ',', '.') : '-' }}</td>
                <td>{{ $material->earliestActiveExpiryDate()?->format('d M Y') ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="8">Belum ada bahan baku.</td></tr>
        @endforelse
    </table>
</body>
</html>
