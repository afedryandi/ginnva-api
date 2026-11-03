<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .sub { color: #6b7280; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 6px; border: 1px solid #d1d5db; text-align: left; }
        th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; }
        td.count { width: 90px; }
        td.note { width: 140px; }
        tr.group td { background: #eef2f7; font-weight: bold; border-left: 0; border-right: 0; }
    </style>
</head>
<body>
    <h1>Lembar Hitung Stok Opname</h1>
    <div class="sub">Dicetak {{ $date->format('d M Y H:i') }} · Isi kolom Hasil Hitung dengan jumlah fisik yang ditemukan. Stok sistem sengaja tidak dicantumkan.</div>

    <table>
        <tr><th>Kode</th><th>Nama</th><th>Satuan</th><th>Hasil Hitung</th><th>Catatan</th></tr>
        @php $group = null; @endphp
        @forelse ($rows as $row)
            @php $heading = $row['jenis'] . ' — ' . ($row['category'] ?: 'Tanpa kategori'); @endphp
            @if ($heading !== $group)
                @php $group = $heading; @endphp
                <tr class="group"><td colspan="5">{{ $heading }}</td></tr>
            @endif
            <tr>
                <td>{{ $row['code'] ?: '-' }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['unit'] }}</td>
                <td class="count"></td>
                <td class="note"></td>
            </tr>
        @empty
            <tr><td colspan="5">Tidak ada barang.</td></tr>
        @endforelse
    </table>
</body>
</html>