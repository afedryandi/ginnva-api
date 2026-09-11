<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .summary { margin-bottom: 16px; font-size: 11px; color: #374151; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.value, th.value { text-align: right; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
        $today = now()->startOfDay();
    @endphp

    <h1>Laporan Stok Kedaluwarsa</h1>
    <div class="period">Rentang kedaluwarsa: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        Sudah Kedaluwarsa: {{ number_format($result['expiredCount'], 0, ',', '.') }} —
        Belum Kedaluwarsa (dalam rentang): {{ number_format($result['nearExpiryCount'], 0, ',', '.') }} —
        Total Nilai Terdampak: {{ $rupiah($result['totalValue']) }}
    </div>

    <table>
        <thead>
            <tr>
                <th>SKU</th>
                <th>Bahan Baku</th>
                <th>Tanggal Terima</th>
                <th>Tanggal Kedaluwarsa</th>
                <th class="value">Sisa Qty</th>
                <th class="value">Nilai</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['batches'] as $batch)
                @php $isExpired = $batch->expiry_date->lt($today); @endphp
                <tr>
                    <td>{{ $batch->rawMaterial?->code ?? '-' }}</td>
                    <td>{{ $batch->rawMaterial?->name ?? '-' }}</td>
                    <td>{{ $batch->received_date?->format('d M Y') ?? '-' }}</td>
                    <td>{{ $batch->expiry_date->format('d M Y') }}</td>
                    <td class="value">{{ number_format((float) $batch->quantity, 2, ',', '.') }} {{ $batch->rawMaterial?->unit }}</td>
                    <td class="value">{{ $rupiah((float) $batch->quantity * (float) ($batch->unit_cost ?? 0)) }}</td>
                    <td>{{ $isExpired ? 'Kedaluwarsa (' . $today->diffInDays($batch->expiry_date) . ' hari lalu)' : 'Kedaluwarsa dalam ' . $today->diffInDays($batch->expiry_date) . ' hari' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
