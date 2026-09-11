<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .note { font-size: 10px; color: #92400e; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: right; }
        td:first-child, th:first-child { text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
    </style>
</head>
<body>
    <h1>Laporan Reservasi &amp; Utilisasi</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="note">
        Kapasitas dihitung dari setting SAAT INI tiap toko (Kapasitas Instalasi/Hari), bukan kapasitas persis yang
        berlaku di hari tertentu di masa lalu — angka Utilisasi ini pendekatan, bukan catatan historis pasti.
    </div>

    <table>
        <thead>
            <tr>
                <th>Toko</th>
                <th>Hari Kerja</th>
                <th>Kapasitas/Hari</th>
                <th>Total Kapasitas</th>
                <th>Terpakai</th>
                <th>Utilisasi</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['store']->name }}</td>
                    <td>{{ number_format($row['workingDays'], 0, ',', '.') }}</td>
                    <td>{{ number_format($row['capacityPerDay'], 0, ',', '.') }}</td>
                    <td>{{ number_format($row['totalCapacity'], 0, ',', '.') }}</td>
                    <td>{{ number_format($row['totalUsed'], 0, ',', '.') }}</td>
                    <td>{{ number_format($row['utilizationPct'], 1, ',', '.') }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
