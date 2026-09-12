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
            'pending' => 'Menunggu Persetujuan',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'fulfilled' => 'Terpenuhi',
            default => $s,
        };
        $itemTypeLabel = fn (string $t) => match ($t) {
            'raw_material' => 'Bahan Baku',
            'consumable_item' => 'Barang Habis Pakai',
            'asset' => 'Aset Baru',
            default => $t,
        };
    @endphp

    <h1>Daftar Permohonan Pembelian</h1>

    <table>
        <tr>
            <th>No. Permohonan</th><th>Barang</th><th>Jenis</th><th>Jumlah</th><th>Toko</th>
            <th>Status</th><th class="value">Biaya Aktual</th><th>Diajukan Oleh</th><th>Tanggal</th>
        </tr>
        @forelse ($requests as $request)
            <tr>
                <td>{{ $request->request_number }}</td>
                <td>{{ $request->item_name }}</td>
                <td>{{ $itemTypeLabel($request->item_type) }}</td>
                <td>{{ number_format((float) $request->quantity, 2, ',', '.') }}{{ $request->unit ? ' '.$request->unit : '' }}</td>
                <td>{{ $request->store?->name ?? '-' }}</td>
                <td>{{ $statusLabel($request->status) }}</td>
                <td class="value">{{ $request->actual_cost !== null ? number_format((float) $request->actual_cost, 0, ',', '.') : '-' }}</td>
                <td>{{ $request->requester?->name ?? '-' }}</td>
                <td>{{ $request->created_at?->format('d M Y') ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="9">Belum ada permohonan.</td></tr>
        @endforelse
    </table>
</body>
</html>
