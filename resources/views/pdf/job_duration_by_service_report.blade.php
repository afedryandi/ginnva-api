<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .note { margin-bottom: 16px; color: #6b7280; font-size: 10px; font-style: italic; }
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

    <h1>Laporan Proses Produk (Durasi per Layanan)</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="note">
        1 job dengan lebih dari 1 jenis layanan sekaligus menyumbang durasi PENUHNYA ke setiap layanan itu
        (tidak dibagi) -- Jumlah Job lintas baris bisa melebihi jumlah job sungguhan.
    </div>

    <table>
        <thead>
            <tr>
                <th>Jenis Layanan</th>
                <th class="value">Jumlah Job</th>
                <th class="value">Rata-rata</th>
                <th class="value">Tercepat</th>
                <th class="value">Terlama</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['service'] }}</td>
                    <td class="value">{{ number_format($row['jobCount'], 0, ',', '.') }}</td>
                    <td class="value">{{ $fmtHours($row['avgMinutes']) }}</td>
                    <td class="value">{{ $fmtHours($row['minMinutes']) }}</td>
                    <td class="value">{{ $fmtHours($row['maxMinutes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
