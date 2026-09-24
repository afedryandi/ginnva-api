<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        td.indent { padding-left: 20px; }
        td.value { text-align: right; }
        tr.section td { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 11px; }
        tr.total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        tr.grand-total td { font-weight: bold; border-top: 2px solid #374151; font-size: 13px; }
        tr.muted td { color: #6b7280; font-style: italic; }
        .status { margin-top: 12px; padding: 8px 10px; border-radius: 4px; font-size: 11px; }
        .status.balanced { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }
        .status.unbalanced { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Neraca (Balance Sheet)</h1>
    <div class="period">Per Tanggal: {{ $result['as_of']->format('d M Y') }}</div>

    <table>
        <tr class="section"><td colspan="2">Aset</td></tr>
        @forelse ($result['aset']['rows'] as $row)
            <tr><td class="indent">{{ $row['account']->name }}</td><td class="value">{{ $rupiah($row['balance']) }}</td></tr>
        @empty
            <tr class="muted"><td class="indent">Belum ada saldo aset.</td><td class="value">-</td></tr>
        @endforelse
        <tr class="total"><td>Total Aset</td><td class="value">{{ $rupiah($result['aset']['total']) }}</td></tr>
    </table>

    <table>
        <tr class="section"><td colspan="2">Kewajiban</td></tr>
        @forelse ($result['kewajiban']['rows'] as $row)
            <tr><td class="indent">{{ $row['account']->name }}</td><td class="value">{{ $rupiah($row['balance']) }}</td></tr>
        @empty
            <tr class="muted"><td class="indent">Belum ada saldo kewajiban.</td><td class="value">-</td></tr>
        @endforelse
        <tr class="total"><td>Total Kewajiban</td><td class="value">{{ $rupiah($result['kewajiban']['total']) }}</td></tr>
    </table>

    <table>
        <tr class="section"><td colspan="2">Modal</td></tr>
        @forelse ($result['modal']['rows'] as $row)
            <tr><td class="indent">{{ $row['account']->name }}</td><td class="value">{{ $rupiah($row['balance']) }}</td></tr>
        @empty
            <tr class="muted"><td class="indent">Belum ada saldo modal.</td><td class="value">-</td></tr>
        @endforelse
        <tr><td class="indent">Laba (Rugi) Tahun Berjalan</td><td class="value">{{ $rupiah($result['modal']['laba_tahun_berjalan']) }}</td></tr>
        <tr class="total"><td>Total Modal</td><td class="value">{{ $rupiah($result['modal']['total']) }}</td></tr>
        <tr class="grand-total"><td>Total Kewajiban + Modal</td><td class="value">{{ $rupiah($result['total_kewajiban_modal']) }}</td></tr>
    </table>

    @if ($result['is_balanced'])
        <div class="status balanced">Balance — Total Aset ({{ $rupiah($result['aset']['total']) }}) sama dengan Total Kewajiban + Modal.</div>
    @else
        <div class="status unbalanced">TIDAK balance — Total Aset ({{ $rupiah($result['aset']['total']) }}) berbeda dari Total Kewajiban + Modal ({{ $rupiah($result['total_kewajiban_modal']) }}). Periksa jurnal yang mungkin belum lengkap.</div>
    @endif
</body>
</html>
