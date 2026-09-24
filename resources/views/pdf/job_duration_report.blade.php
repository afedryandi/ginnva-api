<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .summary { margin-bottom: 16px; color: #1d4ed8; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td.value, th.value { text-align: right; }
    </style>
</head>
<body>
    @php
        $fmtHours = fn ($minutes) => number_format($minutes / 60, 1, ',', '.') . ' jam';
    @endphp

    <h1>Laporan Proses Order</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        {{ number_format($result['jobs']->count(), 0, ',', '.') }} job —
        rata-rata durasi {{ $result['jobs']->isNotEmpty() ? $fmtHours($result['jobs']->avg('minutes')) : '-' }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>No. SPK</th>
                <th>Cabang</th>
                <th>Customer</th>
                <th>Jenis Layanan</th>
                <th>Teknisi</th>
                <th class="value">Durasi</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['jobs'] as $job)
                <tr>
                    <td>{{ $job['date']->format('d M Y H:i') }}</td>
                    <td>{{ $job['spk_number'] }}</td>
                    <td>{{ $job['store_name'] ?? '-' }}</td>
                    <td>{{ $job['customer_name'] }}</td>
                    <td>{{ implode(', ', $job['services']) }}</td>
                    <td>{{ ! empty($job['technicians']) ? implode(', ', $job['technicians']) : '-' }}</td>
                    <td class="value">{{ intdiv($job['minutes'], 60) }}j {{ $job['minutes'] % 60 }}m</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
