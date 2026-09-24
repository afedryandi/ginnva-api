<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 4px; }
        .summary { margin-bottom: 16px; color: #374151; }
        .summary span { margin-right: 24px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 5px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 8px; }
        td.value, th.value { text-align: right; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format($n ?? 0, 0, ',', '.');
    @endphp

    <h1>Laporan Pelanggan</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>
    <div class="summary">
        <span>Pelanggan Baru Daftar: {{ number_format($result['newCustomers'], 0, ',', '.') }}</span>
        <span>Pelanggan Repeat: {{ number_format($result['repeatCount'], 0, ',', '.') }}</span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Pelanggan</th>
                <th>Kontak</th>
                <th>Tanggal Registrasi</th>
                <th class="value">Booking (Periode Ini)</th>
                <th class="value">Belanja (Periode Ini)</th>
                <th class="value">Total Booking</th>
                <th class="value">Total Belanja</th>
                <th>Kunjungan Terakhir</th>
                <th class="value">Rata-rata/Bulan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($result['topCustomers'] as $customer)
                <tr>
                    <td>{{ $customer->name }}</td>
                    <td>{{ $customer->phone_number ?? $customer->email ?? '-' }}</td>
                    <td>{{ $customer->created_at?->format('d M Y') }}</td>
                    <td class="value">{{ $customer->bookings_in_period }}</td>
                    <td class="value">{{ $rupiah($customer->spend_in_period) }}</td>
                    <td class="value">{{ $customer->bookings_all_time }}</td>
                    <td class="value">{{ $rupiah($customer->spend_all_time) }}</td>
                    <td>{{ $customer->last_visit ? \Illuminate\Support\Carbon::parse($customer->last_visit)->format('d M Y') : '-' }}</td>
                    <td class="value">{{ $rupiah($customer->avg_spend_per_month) }}</td>
                </tr>
            @empty
                <tr><td colspan="9">Belum ada pelanggan dengan booking berbayar pada rentang ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
