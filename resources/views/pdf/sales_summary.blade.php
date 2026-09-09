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
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
    @endphp

    <h1>Ringkasan Penjualan</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <table>
        <tr class="section"><td colspan="2">Pendapatan</td></tr>
        <tr><td>Penjualan Kotor</td><td class="value">{{ $rupiah($result['grossSales']) }}</td></tr>
        <tr class="muted"><td>Ongkos Kirim</td><td class="value">Tidak berlaku</td></tr>
        <tr class="muted"><td>Biaya Pelayanan / MDR</td><td class="value">Tidak berlaku</td></tr>
        <tr class="muted"><td>Pajak (PPN)</td><td class="value">Belum tersedia</td></tr>
        <tr class="total"><td>Total Pendapatan</td><td class="value">{{ $rupiah($result['grossSales']) }}</td></tr>
    </table>

    <table>
        <tr class="section"><td colspan="2">Biaya Promosi</td></tr>
        <tr><td>Promo Voucher</td><td class="value">({{ $rupiah($result['voucherDiscount']) }})</td></tr>
        <tr class="muted"><td>Reward Poin (nilai Rp)</td><td class="value">Belum tersedia</td></tr>
        <tr class="total"><td>Total Biaya Promosi</td><td class="value">({{ $rupiah($result['voucherDiscount']) }})</td></tr>
    </table>

    <table>
        <tr class="section"><td colspan="2">Penjualan Bersih</td></tr>
        <tr><td>Total Penjualan</td><td class="value">{{ $rupiah($result['grossSales']) }}</td></tr>
        <tr class="muted"><td>Pengembalian (Refund)</td><td class="value">Belum tersedia</td></tr>
        <tr class="total"><td>Total Penjualan Bersih</td><td class="value">{{ $rupiah($result['netSales']) }}</td></tr>
    </table>

    <table>
        <tr class="section"><td colspan="2">Laba Kotor</td></tr>
        <tr><td>Penjualan Bersih</td><td class="value">{{ $rupiah($result['netSales']) }}</td></tr>
        <tr class="muted"><td>HPP (Harga Pokok Penjualan)</td><td class="value">Belum tersedia</td></tr>
        <tr class="muted"><td>Komisi Partner</td><td class="value">Belum tersedia</td></tr>
        <tr class="total muted"><td>Total Laba Kotor</td><td class="value">Belum tersedia</td></tr>
    </table>

    <p>Jumlah Transaksi: {{ number_format($result['bookingCount'], 0, ',', '.') }}</p>
</body>
</html>
