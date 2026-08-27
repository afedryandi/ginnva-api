<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Slip Gaji {{ $payroll->user->name }} - {{ $periodLabel }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.4; }
        .header { width: 100%; margin-bottom: 25px; border-bottom: 2px solid #222; padding-bottom: 15px; }
        .brand { font-size: 20px; font-weight: bold; color: #111; }
        .title { font-size: 18px; font-weight: bold; text-align: right; color: #111; text-transform: uppercase; }
        .draft-badge { display: inline-block; background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; margin-top: 4px; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .meta-table td { padding: 4px 0; vertical-align: top; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .items-table th { background-color: #111; color: #fff; text-align: left; padding: 8px 10px; font-weight: bold; text-transform: uppercase; font-size: 11px; }
        .items-table td { padding: 9px 10px; border-bottom: 1px solid #eee; }
        .text-right { text-align: right; }
        .negative { color: #b91c1c; }
        .total-box { background-color: #f9f9f9; padding: 15px; border: 1px solid #e4e4e4; font-size: 17px; font-weight: bold; text-align: right; margin-top: 15px; }
        .footer { margin-top: 50px; width: 100%; font-size: 10px; color: #888; }
        .signature-box { width: 45%; float: right; text-align: center; margin-top: 40px; }
        .signature-line { border-top: 1px solid #333; margin-top: 60px; padding-top: 4px; }
    </style>
</head>
<body>

    <table class="header">
        <tr>
            <td>
                <div class="brand">GINNVA</div>
            </td>
            <td class="title">
                Slip Gaji Karyawan
                @if ($payroll->status !== 'paid')
                    <br><span class="draft-badge">DRAFT — BELUM FINAL</span>
                @endif
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td style="width: 18%;"><strong>Nama Karyawan</strong></td>
            <td style="width: 32%;">: {{ $payroll->user->name }}</td>
            <td style="width: 18%;"><strong>Periode</strong></td>
            <td style="width: 32%;">: {{ $periodLabel }}</td>
        </tr>
        <tr>
            <td><strong>Toko</strong></td>
            <td>: {{ $payroll->store?->name ?? '—' }}</td>
            <td><strong>Hari Kerja Toko</strong></td>
            <td>: {{ $payroll->working_days_in_month }} hari</td>
        </tr>
        <tr>
            <td><strong>Status</strong></td>
            <td>: {{ $payroll->status === 'paid' ? 'Sudah Dibayar' : 'Draft (belum final)' }}</td>
            <td><strong>Tanggal Dibayar</strong></td>
            <td>: {{ $payroll->paid_at?->translatedFormat('d F Y') ?? '—' }}</td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Komponen</th>
                <th class="text-right">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Gaji Pokok</td>
                <td class="text-right">Rp {{ number_format($payroll->base_salary, 0, ',', '.') }}</td>
            </tr>
            @if ($payroll->prorated_base_salary < $payroll->base_salary)
            <tr>
                <td>Gaji Berjalan (diproporsikan — baru mulai kerja bulan ini)</td>
                <td class="text-right">Rp {{ number_format($payroll->prorated_base_salary, 0, ',', '.') }}</td>
            </tr>
            @endif
            @if ($payroll->late_violation_days > 0)
            <tr>
                <td>Potongan Telat ({{ $payroll->late_violation_days }} hari x Rp {{ number_format($payroll->deduction_per_violation, 0, ',', '.') }})</td>
                <td class="text-right negative">- Rp {{ number_format($payroll->total_deduction - $payroll->alpha_deduction, 0, ',', '.') }}</td>
            </tr>
            @endif
            @if ($payroll->alpha_days > 0)
            <tr>
                <td>Potongan Alpha / Tanpa Keterangan ({{ $payroll->alpha_days }} hari)</td>
                <td class="text-right negative">- Rp {{ number_format($payroll->alpha_deduction, 0, ',', '.') }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="total-box">
        GAJI BERSIH: Rp {{ number_format($payroll->net_pay, 0, ',', '.') }}
    </div>

    <div class="signature-box">
        <div>{{ $payroll->payer?->name ?? '_________________' }}</div>
        <div class="signature-line">Disetujui Oleh</div>
    </div>

    <div class="footer">
        Dokumen ini digenerate otomatis oleh sistem Ginnva pada {{ now()->translatedFormat('d F Y H:i') }}.
        @if ($payroll->status !== 'paid')
            Angka pada slip DRAFT ini masih bisa berubah sampai ditandai "Sudah Dibayar".
        @endif
    </div>

</body>
</html>