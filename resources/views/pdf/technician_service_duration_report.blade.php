<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .note { margin-bottom: 16px; color: #6b7280; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td.value, th.value { text-align: right; }
    </style>
</head>
<body>
    <h1>Akumulasi Durasi Servis Teknisi</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="note">
        Durasi diambil dari waktu kendaraan masuk-keluar bengkel (SPK) — data AKTUAL, bukan estimasi. Kalau 1 job
        dikerjakan tim (lebih dari 1 installer), durasi PENUH dikreditkan ke setiap orang di tim itu (tidak dibagi
        rata). Laporan ini murni akumulasi — belum ada perhitungan komisi bertingkat.
    </div>

    <table>
        <thead>
            <tr>
                <th>Teknisi</th>
                <th>Cabang</th>
                <th class="value">Jumlah Job</th>
                <th class="value">Total Durasi (Jam)</th>
                <th class="value">Rata-rata per Job (Menit)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['store_name'] ?? '-' }}</td>
                    <td class="value">{{ number_format($row['job_count'], 0, ',', '.') }}</td>
                    <td class="value">{{ number_format($row['total_hours'], 1, ',', '.') }} jam</td>
                    <td class="value">{{ $row['job_count'] > 0 ? number_format($row['avg_minutes_per_job'], 0, ',', '.') . ' menit' : '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">Belum ada teknisi aktif untuk cabang ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
