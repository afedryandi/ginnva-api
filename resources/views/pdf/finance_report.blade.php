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
        td.value { text-align: right; }
        tr.section td { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 11px; }
        tr.total td { font-weight: bold; }
        tr.muted td { color: #9ca3af; font-style: italic; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Laporan Keuangan</h1>
    <div class="period">Bulan: {{ $result['month']->translatedFormat('F Y') }}</div>

    <table>
        <tr class="section"><td colspan="2">Ringkasan</td></tr>
        <tr><td>Total Pemasukan</td><td class="value">{{ $rupiah($result['totals']['in']) }}</td></tr>
        <tr><td>Total Pengeluaran</td><td class="value">{{ $rupiah($result['totals']['out']) }}</td></tr>
        <tr class="total"><td>Saldo Bersih</td><td class="value">{{ $rupiah($result['totals']['net']) }}</td></tr>
    </table>

    <table>
        <tr class="section"><td colspan="2">Rincian Pemasukan per Kategori</td></tr>
        @forelse ($result['income'] as $row)
            <tr><td>{{ $row['category'] }}</td><td class="value">{{ $rupiah($row['total']) }}</td></tr>
        @empty
            <tr class="muted"><td colspan="2">Belum ada transaksi pemasukan di periode ini.</td></tr>
        @endforelse
    </table>

    <table>
        <tr class="section"><td colspan="2">Rincian Pengeluaran per Kategori</td></tr>
        @forelse ($result['expense'] as $row)
            <tr><td>{{ $row['category'] }}</td><td class="value">{{ $rupiah($row['total']) }}</td></tr>
        @empty
            <tr class="muted"><td colspan="2">Belum ada transaksi pengeluaran di periode ini.</td></tr>
        @endforelse
    </table>

    @if ($result['pinnedAccounts']->isNotEmpty())
        <table>
            <tr class="section"><td colspan="2">Saldo Akun Pilihan</td></tr>
            @foreach ($result['pinnedAccounts'] as $row)
                <tr><td>{{ $row['account']->display_name }}</td><td class="value">{{ $rupiah($row['balance']) }}</td></tr>
            @endforeach
        </table>
    @endif
</body>
</html>
