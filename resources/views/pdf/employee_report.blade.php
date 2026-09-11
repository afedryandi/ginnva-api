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

    <h1>Laporan Karyawan</h1>
    <div class="period">Bulan: {{ $result['month']->translatedFormat('F Y') }}</div>
    <div class="summary">
        Total Gaji Bersih: {{ $rupiah($result['totalNetPay']) }} —
        Total Hari Alpha: {{ number_format($result['totalAlphaDays'], 0, ',', '.') }} —
        Total Menit Telat: {{ number_format($result['totalLateMinutes'], 0, ',', '.') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Karyawan</th>
                <th>Toko</th>
                <th>Hari Kerja</th>
                <th>Telat (Menit)</th>
                <th>Alpha (Hari)</th>
                <th>Gaji Bersih</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['payrolls'] as $payroll)
                <tr>
                    <td>{{ $payroll->user?->name ?? '-' }}</td>
                    <td>{{ $payroll->store?->name ?? '-' }}</td>
                    <td>{{ number_format($payroll->working_days_in_month, 0, ',', '.') }}</td>
                    <td>{{ number_format($payroll->total_late_minutes, 0, ',', '.') }}</td>
                    <td>{{ number_format($payroll->alpha_days, 0, ',', '.') }}</td>
                    <td>{{ $rupiah($payroll->net_pay) }}</td>
                    <td>{{ $payroll->status === 'paid' ? 'Sudah Dibayar' : 'Draft' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
