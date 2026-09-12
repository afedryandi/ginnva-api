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
            'aktif' => 'Aktif Dipakai',
            'diperbaiki' => 'Sedang Diperbaiki',
            'rusak' => 'Rusak',
            'dijual' => 'Dijual',
            'hilang' => 'Hilang',
            default => $s,
        };
    @endphp

    <h1>Daftar Aset Tetap</h1>

    <table>
        <tr>
            <th>Kode</th><th>Nama Aset</th><th>Kategori</th><th>Status</th><th>Dipegang Oleh</th>
            <th>Lokasi</th><th>Tanggal Beli</th><th class="value">Harga Beli</th><th class="value">Nilai Buku Saat Ini</th>
        </tr>
        @forelse ($assets as $asset)
            <tr>
                <td>{{ $asset->asset_tag }}</td>
                <td>{{ $asset->name }}</td>
                <td>{{ $asset->category ?? '-' }}</td>
                <td>{{ $statusLabel($asset->status) }}</td>
                <td>{{ $asset->assignee?->name ?? '-' }}</td>
                <td>{{ $asset->store?->name ?? 'Kantor Pusat' }}</td>
                <td>{{ $asset->purchase_date?->format('d M Y') ?? '-' }}</td>
                <td class="value">{{ $asset->purchase_cost !== null ? number_format((float) $asset->purchase_cost, 0, ',', '.') : '-' }}</td>
                <td class="value">{{ $asset->currentBookValue() !== null ? number_format((float) $asset->currentBookValue(), 0, ',', '.') : '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="9">Belum ada aset.</td></tr>
        @endforelse
    </table>
</body>
</html>
