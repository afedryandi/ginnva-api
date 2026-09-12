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
    <h1>Perputaran Stok</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <table>
        <tr>
            <th>Nama</th><th>Jenis</th><th class="value">Terpakai</th><th class="value">Sisa</th>
            <th class="value">Rata-rata Stok</th><th class="value">Perputaran Stok</th><th class="value">Hari Terjual</th>
        </tr>
        @forelse ($result['rows'] as $row)
            <tr>
                <td>{{ $row['item']->name }}</td>
                <td>{{ $row['type'] }}</td>
                <td class="value">{{ number_format($row['qtyOut'], 2, ',', '.') }} {{ $row['item']->unit }}</td>
                <td class="value">{{ number_format($row['stockAtTo'], 2, ',', '.') }} {{ $row['item']->unit }}</td>
                <td class="value">{{ number_format($row['avgStock'], 2, ',', '.') }} {{ $row['item']->unit }}</td>
                <td class="value">{{ $row['turnoverRatio'] !== null ? number_format($row['turnoverRatio'], 2, ',', '.') . 'x' : '-' }}</td>
                <td class="value">{{ $row['daysSold'] }} Hari</td>
            </tr>
        @empty
            <tr><td colspan="7">Tidak ada pergerakan stok pada rentang ini.</td></tr>
        @endforelse
    </table>
</body>
</html>
