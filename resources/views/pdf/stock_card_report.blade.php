<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.value, th.value { text-align: right; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    @endphp

    <h1>Daftar Stok (Kartu Stok)</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <table>
        <tr>
            <th>Kode</th><th>Nama</th><th>Jenis</th><th class="value">Awal</th>
            <th class="value">Masuk</th><th class="value">Keluar</th><th class="value">Akhir</th><th>Satuan</th>
        </tr>
        @forelse ($result['rows'] as $row)
            <tr>
                <td>{{ $row['code'] ?: '-' }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['jenis'] }}</td>
                <td class="value">{{ $num($row['awal']) }}</td>
                <td class="value">{{ $row['masuk'] > 0 ? '+'.$num($row['masuk']) : '0' }}</td>
                <td class="value">{{ $row['keluar'] > 0 ? '-'.$num($row['keluar']) : '0' }}</td>
                <td class="value">{{ $num($row['akhir']) }}</td>
                <td>{{ $row['unit'] }}</td>
            </tr>
        @empty
            <tr><td colspan="8">Tidak ada bahan pada jenis ini.</td></tr>
        @endforelse
    </table>
</body>
</html>
