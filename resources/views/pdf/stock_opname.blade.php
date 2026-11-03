<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .sub { color: #6b7280; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.value, th.value { text-align: right; }
        th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; }
        .neg { color: #b91c1c; font-weight: bold; }
        .pos { color: #15803d; font-weight: bold; }
    </style>
</head>
<body>
    @php $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',') ?: '0'; @endphp
    <h1>Berita Acara Stok Opname {{ $opname->opname_number }}</h1>
    <div class="sub">
        Toko {{ $opname->store?->name ?? '-' }} · Tanggal {{ $opname->opname_date->format('d M Y') }} · {{ \App\Models\StockOpname::STATUS_LABELS[$opname->status] ?? $opname->status }}
        @if ($opname->notes) · {{ $opname->notes }} @endif
    </div>
    <table>
        <tr><th>Item</th><th>Jenis</th><th class="value">Stok Sistem</th><th class="value">Hasil Hitung</th><th class="value">Selisih</th><th>Satuan</th><th>Catatan</th></tr>
        @foreach ($opname->items as $item)
            <tr>
                <td>{{ $item->item_name }}</td>
                <td>{{ $item->item_type === 'raw_material' ? 'Bahan Baku' : 'Barang Habis Pakai' }}</td>
                <td class="value">{{ $num($item->system_quantity) }}</td>
                <td class="value">{{ $num($item->actual_quantity) }}</td>
                <td class="value {{ $item->delta < 0 ? 'neg' : ($item->delta > 0 ? 'pos' : '') }}">{{ $item->delta > 0 ? '+' : '' }}{{ $num($item->delta) }}</td>
                <td>{{ $item->unit }}</td>
                <td>{{ $item->note ?: '' }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>