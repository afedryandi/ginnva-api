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
        tr.total td { border-top: 2px solid #9ca3af; font-weight: bold; }
        .neg { color: #b91c1c; font-weight: bold; }
        .note { color: #92400e; margin-top: 8px; }
    </style>
</head>
<body>
    @php
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',') ?: '0';
        $jenisLabel = match ($result['jenis'] ?? 'all') { 'raw_material' => 'Bahan Baku', 'consumable_item' => 'Barang Habis Pakai', default => 'Semua jenis' };
    @endphp

    <h1>Daftar Stok (Kartu Stok)</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }} · {{ $jenisLabel }}</div>

    <table>
        <tr>
            <th>Kode</th><th>Nama</th><th>Jenis</th><th>Kategori</th><th class="value">Awal</th>
            <th class="value">Masuk</th><th class="value">Keluar</th><th class="value">Akhir</th><th>Satuan</th>
        </tr>
        @forelse ($result['rows'] as $row)
            <tr>
                <td>{{ $row['code'] ?: '-' }}</td>
                <td>{{ $row['name'] }}{{ $row['inactive'] ? ' (nonaktif)' : '' }}</td>
                <td>{{ $row['jenis'] }}</td>
                <td>{{ $row['category'] ?: '-' }}</td>
                <td class="value {{ $row['awal'] < 0 ? 'neg' : '' }}">{{ $num($row['awal']) }}</td>
                <td class="value">{{ $row['masuk'] > 0 ? '+'.$num($row['masuk']) : '0' }}</td>
                <td class="value">{{ $row['keluar'] > 0 ? '-'.$num($row['keluar']) : '0' }}</td>
                <td class="value {{ $row['akhir'] < 0 ? 'neg' : '' }}">{{ $num($row['akhir']) }}</td>
                <td>{{ $row['unit'] }}</td>
            </tr>
        @empty
            <tr><td colspan="9">Tidak ada bahan pada jenis ini.</td></tr>
        @endforelse
        @if (count($result['rows']) > 0)
            <tr class="total">
                <td colspan="5">Total {{ $result['totals']['count'] }} barang</td>
                <td class="value">+{{ $num($result['totals']['masuk']) }}</td>
                <td class="value">-{{ $num($result['totals']['keluar']) }}</td>
                <td colspan="2"></td>
            </tr>
        @endif
    </table>

    @if (($result['totals']['anomalies'] ?? 0) > 0)
        <div class="note">{{ $result['totals']['anomalies'] }} barang punya Awal atau Akhir negatif (stok dan riwayat tidak sinkron); cek Rekonsiliasi di menu Riwayat.</div>
    @endif
</body>
</html>