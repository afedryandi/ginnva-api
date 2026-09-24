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
        td.muted { color: #9ca3af; font-style: italic; }
    </style>
</head>
<body>
    <h1>Laporan Utilisasi Teknisi</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="note">
        Jam Hadir: sungguhan dari absensi (clock-in/clock-out). Jam Job: estimasi dari hari kerja yang direncanakan
        per booking (booking belum mencatat jam mulai/selesai kerja yang sesungguhnya). Utilisasi di atas 100% bukan
        bug — bisa berarti job dikerjakan di luar jam normal, dikerjakan tim, atau estimasi durasinya lebih besar
        dari kenyataan.
    </div>

    <table>
        <thead>
            <tr>
                <th>Teknisi</th>
                <th>Cabang</th>
                <th class="value">Jam Hadir</th>
                <th class="value">Jam Job (Estimasi)</th>
                <th class="value">Jam Idle</th>
                <th class="value">Utilisasi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['store_name'] ?? '-' }}</td>
                    <td class="value @if (! $row['has_account']) muted @endif">
                        {{ $row['has_account'] ? number_format($row['present_hours'], 1, ',', '.') . ' jam' : 'Belum ada akun' }}
                    </td>
                    <td class="value">{{ number_format($row['job_hours'], 1, ',', '.') }} jam</td>
                    <td class="value">{{ $row['idle_hours'] !== null ? number_format($row['idle_hours'], 1, ',', '.') . ' jam' : '-' }}</td>
                    <td class="value">{{ $row['utilization_percent'] !== null ? number_format($row['utilization_percent'], 1, ',', '.') . '%' : '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Belum ada teknisi aktif untuk cabang ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
