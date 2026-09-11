<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .summary { margin-bottom: 16px; font-size: 11px; color: #374151; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: right; }
        td:first-child, td:nth-child(2), th:first-child, th:nth-child(2) { text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>{{ $title }}</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        Total Komisi Periode Ini: {{ $rupiah($result['totalCommission']) }}
        @if ($result['unratedCount'] > 0)
            — {{ $result['unratedCount'] }} teknisi punya pekerjaan tapi komisi belum diatur
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Teknisi</th>
                <th>Toko</th>
                <th>Jumlah Pekerjaan</th>
                <th>Penjualan</th>
                <th>Komisi/Pekerjaan</th>
                <th>Total Komisi</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['technician']->name }}</td>
                    <td>{{ $row['technician']->store?->name ?? '-' }}</td>
                    <td>{{ number_format($row['jobCount'], 0, ',', '.') }}</td>
                    <td>{{ $rupiah($row['salesTotal']) }}</td>
                    <td>{{ $row['rate'] !== null ? $rupiah($row['rate']) : 'Belum diatur' }}</td>
                    <td>{{ $row['totalCommission'] !== null ? $rupiah($row['totalCommission']) : 'Belum diatur' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
