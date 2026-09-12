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
    @php
        $statusLabel = fn (string $s) => match ($s) {
            'in_stock' => 'Ada Stok',
            'out' => 'Sudah Keluar',
            default => $s,
        };
    @endphp

    <h1>Daftar Produk PPF/WF</h1>

    <table>
        <tr>
            <th>Kode</th><th>Nama Produk</th><th>Kategori</th><th>Kode Gulungan</th>
            <th class="value">Sisa Panjang</th><th class="value">Total Panjang</th><th>Tanggal Masuk</th><th>Status</th>
        </tr>
        @forelse ($items as $item)
            <tr>
                <td>{{ $item->code }}</td>
                <td>{{ $item->name }}</td>
                <td>{{ $item->category ?? '-' }}</td>
                <td>{{ $item->scrollCode?->code ?? '-' }}</td>
                <td class="value">{{ $item->scrollCode?->remaining_length_meters !== null ? number_format((float) $item->scrollCode->remaining_length_meters, 2, ',', '.') . ' m' : '-' }}</td>
                <td class="value">{{ $item->scrollCode?->total_length_meters !== null ? number_format((float) $item->scrollCode->total_length_meters, 2, ',', '.') . ' m' : '-' }}</td>
                <td>{{ $item->received_date?->format('d M Y') ?? '-' }}</td>
                <td>{{ $statusLabel($item->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="8">Belum ada produk.</td></tr>
        @endforelse
    </table>
</body>
</html>
