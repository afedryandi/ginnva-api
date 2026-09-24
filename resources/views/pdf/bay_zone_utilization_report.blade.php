<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .store { color: #1f2937; font-weight: bold; margin-bottom: 16px; }
        h2 { font-size: 13px; margin: 18px 0 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td.value, th.value { text-align: right; }
        .not-configured { color: #9ca3af; font-style: italic; }
    </style>
</head>
<body>
    <h1>Laporan Utilisasi Zona/Bay</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="store">Toko: {{ $result['store']?->name ?? '-' }}</div>

    @foreach ($result['zones'] as $zone)
        <h2>{{ $zone['label'] }}</h2>

        @if (! $zone['configured'])
            <p class="not-configured">Zona ini belum dikonfigurasi — isi jumlah slot di halaman Toko/Dealer.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Kapasitas (Slot)</th>
                        <th>Jumlah Booking Lewat Zona Ini</th>
                        <th class="value">Jam Tersedia</th>
                        <th class="value">Jam Terpakai</th>
                        <th class="value">Utilisasi %</th>
                        <th class="value">Peak Bersamaan</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $zone['slotCount'] }}</td>
                        <td>{{ number_format($zone['bookingCount'], 0, ',', '.') }}</td>
                        <td class="value">{{ number_format($zone['availableHours'], 1, ',', '.') }} jam</td>
                        <td class="value">{{ number_format($zone['occupiedHours'], 1, ',', '.') }} jam</td>
                        <td class="value">{{ number_format($zone['utilizationPct'], 1, ',', '.') }}%</td>
                        <td class="value">{{ $zone['peakConcurrent'] }}</td>
                    </tr>
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
