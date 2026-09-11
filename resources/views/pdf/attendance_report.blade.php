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
        $entryTypeLabel = fn (string $type) => match ($type) {
            'clock' => 'Clock In/Out',
            'manual' => 'Manual',
            'field_duty' => 'Tugas Lapangan',
            'alpha' => 'Alpha',
            'leave' => 'Izin/Cuti',
            default => $type,
        };
    @endphp

    <h1>Laporan Absensi</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        Tepat Waktu: {{ number_format($result['onTimeCount'], 0, ',', '.') }} —
        Terlambat: {{ number_format($result['lateCount'], 0, ',', '.') }} —
        Pulang Cepat: {{ number_format($result['earlyLeaveCount'], 0, ',', '.') }} —
        Alpha: {{ number_format($result['alphaCount'], 0, ',', '.') }} —
        Izin/Cuti: {{ number_format($result['leaveCount'], 0, ',', '.') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Nama</th>
                <th>Toko</th>
                <th>Tanggal</th>
                <th>Absen Masuk</th>
                <th>Absen Keluar</th>
                <th class="value">Terlambat</th>
                <th class="value">Pulang Cepat</th>
                <th>Jenis</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                <tr>
                    <td>{{ $row->user?->name ?? '-' }}</td>
                    <td>{{ $row->store?->name ?? '-' }}</td>
                    <td>{{ $row->date?->format('d M Y') }}</td>
                    <td>{{ $row->clock_in_at?->format('H:i') ?? '-' }}</td>
                    <td>{{ $row->clock_out_at?->format('H:i') ?? '-' }}</td>
                    <td class="value">{{ $row->late_minutes > 0 ? $row->late_minutes . ' menit' : '-' }}</td>
                    <td class="value">{{ $row->early_leave_minutes > 0 ? $row->early_leave_minutes . ' menit' : '-' }}</td>
                    <td>{{ $entryTypeLabel($row->entry_type) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
