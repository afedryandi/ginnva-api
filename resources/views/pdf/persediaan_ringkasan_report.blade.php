<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .note { color: #6b7280; margin-bottom: 16px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: right; }
        td:first-child, td:nth-child(2), td:nth-child(3), td:nth-child(4), th:first-child, th:nth-child(2), th:nth-child(3), th:nth-child(4) { text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        tr.total td { font-weight: bold; border-top: 2px solid #1f2937; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Ringkasan Persediaan</h1>
    <div class="note">Kondisi stok &amp; harga modal terkini pada saat export — bukan snapshot historis per tanggal.</div>

    <table>
        <thead>
            <tr>
                <th>Nama Produk</th>
                <th>SKU</th>
                <th>Jenis</th>
                <th>Kategori</th>
                <th>Kuantitas</th>
                <th>Satuan</th>
                <th>Harga Modal</th>
                <th>Total Nilai Persediaan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['sku'] }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td>{{ $row['category'] }}</td>
                    <td>{{ number_format($row['quantity'], 2, ',', '.') }}</td>
                    <td>{{ $row['unit'] }}</td>
                    <td>{{ $rupiah($row['unitCost']) }}</td>
                    <td>{{ $rupiah($row['totalValue']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="7">Total Nilai Persediaan</td>
                <td>{{ $rupiah($result['totalValue']) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
