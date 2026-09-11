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
        $statusLabel = fn (string $status) => match ($status) {
            'unallocated' => 'Belum Dialokasikan',
            'allocated' => 'Dialokasikan',
            'used' => 'Habis Dipakai',
            default => $status,
        };
    @endphp

    <h1>Laporan Serial Number</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <h2>Daftar Serial Number (Roll) — {{ number_format($result['totalCount'], 0, ',', '.') }} roll, {{ number_format($result['usedCount'], 0, ',', '.') }} habis dipakai</h2>
    <table>
        <tr>
            <th>Kode Serial</th><th>Produk</th><th>Toko</th><th class="value">Panjang Total</th>
            <th class="value">Sisa Panjang</th><th>Tgl Alokasi</th><th>Tgl Habis Dipakai</th><th>Status</th>
        </tr>
        @forelse ($result['codes'] as $code)
            <tr>
                <td>{{ $code->code }}</td>
                <td>{{ $code->filmProduct ? "{$code->filmProduct->sku} - {$code->filmProduct->name}" : '-' }}</td>
                <td>{{ $code->store?->name ?? '-' }}</td>
                <td class="value">{{ number_format((float) $code->total_length_meters, 2, ',', '.') }} m</td>
                <td class="value">{{ number_format((float) $code->remaining_length_meters, 2, ',', '.') }} m</td>
                <td>{{ $code->allocated_at?->format('d M Y') ?? '-' }}</td>
                <td>{{ $code->used_at?->format('d M Y') ?? '-' }}</td>
                <td>{{ $statusLabel($code->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="8">Tidak ada serial number pada rentang/filter ini.</td></tr>
        @endforelse
    </table>

    <h2>Riwayat Pemakaian — total {{ number_format($result['totalMetersUsed'], 2, ',', '.') }} m dipakai</h2>
    <table>
        <tr><th>Tanggal</th><th>Kode Serial</th><th>Toko</th><th class="value">Meter Dipakai</th><th>Oleh</th><th>Catatan</th></tr>
        @forelse ($result['usages'] as $usage)
            <tr>
                <td>{{ $usage->created_at->format('d M Y H:i') }}</td>
                <td>{{ $usage->scrollCode?->code ?? '-' }}</td>
                <td>{{ $usage->scrollCode?->store?->name ?? '-' }}</td>
                <td class="value">{{ number_format((float) $usage->meters, 2, ',', '.') }} m</td>
                <td>{{ $usage->user?->name ?? '-' }}</td>
                <td>{{ $usage->note ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="6">Tidak ada pemakaian roll pada rentang ini.</td></tr>
        @endforelse
    </table>
</body>
</html>
