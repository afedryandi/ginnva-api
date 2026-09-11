<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        h2 { font-size: 11px; text-transform: uppercase; margin: 16px 0 6px; color: #6b7280; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.value, th.value { text-align: right; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
        $typeLabel = fn (string $type) => match ($type) {
            'in' => 'Masuk',
            'out' => 'Keluar',
            'adjustment' => 'Penyesuaian',
            default => $type,
        };
    @endphp

    <h1>Detail Persediaan</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <h2>Pergerakan Bahan Baku ({{ $result['materialInCount'] }} masuk / {{ $result['materialOutCount'] }} keluar)</h2>
    <table>
        <tr><th>Tanggal</th><th>Bahan Baku</th><th>Jenis</th><th class="value">Jumlah</th><th class="value">Harga Beli</th><th>Oleh</th><th>Catatan</th></tr>
        @forelse ($result['materialMovements'] as $movement)
            <tr>
                <td>{{ $movement->created_at->format('d M Y H:i') }}</td>
                <td>{{ $movement->rawMaterial?->name ?? '-' }}</td>
                <td>{{ $typeLabel($movement->type) }}</td>
                <td class="value">{{ number_format((float) $movement->quantity, 2, ',', '.') }} {{ $movement->rawMaterial?->unit }}</td>
                <td class="value">{{ $movement->unit_cost ? $rupiah($movement->unit_cost) : '-' }}</td>
                <td>{{ $movement->user?->name ?? '-' }}</td>
                <td>{{ $movement->note ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Tidak ada pergerakan bahan baku pada rentang ini.</td></tr>
        @endforelse
    </table>

    <h2>Pergerakan Barang Habis Pakai ({{ $result['consumableInCount'] }} masuk / {{ $result['consumableOutCount'] }} keluar)</h2>
    <table>
        <tr><th>Tanggal</th><th>Barang</th><th>Jenis</th><th class="value">Jumlah</th><th class="value">Harga Beli</th><th>Oleh</th><th>Catatan</th></tr>
        @forelse ($result['consumableMovements'] as $movement)
            <tr>
                <td>{{ $movement->created_at->format('d M Y H:i') }}</td>
                <td>{{ $movement->consumableItem?->name ?? '-' }}</td>
                <td>{{ $typeLabel($movement->type) }}</td>
                <td class="value">{{ number_format((float) $movement->quantity, 2, ',', '.') }} {{ $movement->consumableItem?->unit }}</td>
                <td class="value">{{ $movement->unit_cost ? $rupiah($movement->unit_cost) : '-' }}</td>
                <td>{{ $movement->user?->name ?? '-' }}</td>
                <td>{{ $movement->note ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Tidak ada pergerakan barang habis pakai pada rentang ini.</td></tr>
        @endforelse
    </table>
</body>
</html>
