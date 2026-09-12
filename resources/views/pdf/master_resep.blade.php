<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 12px; margin: 14px 0 4px; }
        .type { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.value, th.value { text-align: right; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $typeLabel = fn (string $t) => match ($t) {
            'window_film' => 'Kaca Film',
            'ppf' => 'PPF',
            'detailing' => 'Detailing',
            'color_change' => 'Ganti Warna',
            default => $t,
        };
        $itemTypeLabel = fn (string $t) => match ($t) {
            'raw_material' => 'Bahan Baku',
            'consumable_item' => 'Barang Habis Pakai',
            'film_roll' => 'Roll Film (meteran)',
            default => $t,
        };
    @endphp

    <h1>Master Resep</h1>

    @foreach ($products as $product)
        <h2>{{ $product->sku }} — {{ $product->name }}</h2>
        <div class="type">{{ $typeLabel($product->product_type) }}</div>

        <table>
            <tr>
                <th>Jenis Bahan</th><th>Nama Bahan</th><th class="value">Jumlah</th><th>Satuan</th><th>Catatan</th>
            </tr>
            @forelse ($product->recipeItems as $item)
                <tr>
                    <td>{{ $itemTypeLabel($item->item_type) }}</td>
                    <td>{{ $item->item_name }}</td>
                    <td class="value">{{ number_format((float) $item->standard_qty, 2, ',', '.') }}</td>
                    <td>{{ $item->unit ?? '-' }}</td>
                    <td>{{ $item->note ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Belum diisi resepnya.</td></tr>
            @endforelse
        </table>
    @endforeach
</body>
</html>
