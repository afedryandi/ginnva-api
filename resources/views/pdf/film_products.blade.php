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
        $typeLabel = fn (string $t) => match ($t) {
            'window_film' => 'Kaca Film',
            'ppf' => 'PPF',
            'detailing' => 'Detailing',
            'color_change' => 'Ganti Warna',
            default => $t,
        };
        $positionLabel = fn (?string $p) => match ($p) {
            'front' => 'Kaca Depan',
            'side_rear' => 'Samping & Belakang',
            default => '-',
        };
    @endphp

    <h1>Daftar Produk</h1>

    <table>
        <tr>
            <th>SKU</th><th>Nama Produk</th><th>Tipe</th><th>Posisi Kaca</th>
            <th class="value">Harga Dasar (Flat)</th><th>Harga per Ukuran</th><th>Aktif</th>
        </tr>
        @forelse ($products as $product)
            <tr>
                <td>{{ $product->sku }}</td>
                <td>{{ $product->name }}</td>
                <td>{{ $typeLabel($product->product_type) }}</td>
                <td>{{ $product->product_type === 'window_film' ? $positionLabel($product->position) : '-' }}</td>
                <td class="value">{{ number_format((float) $product->base_price, 0, ',', '.') }}</td>
                <td>
                    @if ($product->prices->isEmpty())
                        -
                    @else
                        @foreach ($product->prices as $price)
                            {{ $price->vehicle_size }}: Rp{{ number_format((float) $price->price, 0, ',', '.') }}@if (! $loop->last), @endif
                        @endforeach
                    @endif
                </td>
                <td>{{ $product->is_active ? 'Ya' : 'Tidak' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Belum ada produk.</td></tr>
        @endforelse
    </table>
</body>
</html>
