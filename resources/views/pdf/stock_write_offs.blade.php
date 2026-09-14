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
            'raw_material' => 'Bahan Baku',
            'consumable_item' => 'Barang Habis Pakai',
            default => $t,
        };
    @endphp

    <h1>Daftar Stok Terbuang</h1>

    <table>
        <tr>
            <th>Nomor</th><th>Tanggal</th><th>Barang</th><th>Jenis</th><th class="value">Jumlah</th>
            <th>Alasan</th><th class="value">Nilai Kerugian</th><th>Jurnal</th><th>Oleh</th><th>Catatan</th>
        </tr>
        @forelse ($writeOffs as $writeOff)
            <tr>
                <td>{{ $writeOff->write_off_number ?? '-' }}</td>
                <td>{{ $writeOff->created_at?->format('d M Y H:i') }}</td>
                <td>{{ $writeOff->item_name }}</td>
                <td>{{ $typeLabel($writeOff->writeoffable_type) }}</td>
                <td class="value">{{ number_format((float) $writeOff->quantity, 2, ',', '.') }} {{ $writeOff->unit }}</td>
                <td>{{ \App\Models\StockWriteOff::REASON_LABELS[$writeOff->reason] ?? $writeOff->reason }}</td>
                <td class="value">{{ $writeOff->total_value !== null ? number_format((float) $writeOff->total_value, 0, ',', '.') : '-' }}</td>
                <td>{{ $writeOff->journal_entry_id ? 'Ya' : 'Tidak' }}</td>
                <td>{{ $writeOff->creator?->name ?? '-' }}</td>
                <td>{{ $writeOff->note ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="10">Belum ada stok terbuang.</td></tr>
        @endforelse
    </table>
</body>
</html>
