<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        td { padding: 4px 8px; border-bottom: 1px solid #e5e7eb; }
        td.value { text-align: right; }
        tr.top td { font-weight: bold; background: #f3f4f6; }
        tr.section td { font-weight: bold; text-transform: uppercase; font-size: 10px; padding-top: 10px; }
        tr.detail td { padding-left: 20px; color: #4b5563; }
        tr.total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        tr.note td { font-style: italic; color: #6b7280; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => ($n < 0 ? '(' : '') . 'Rp' . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : '');
    @endphp

    <h1>Laporan Arus Kas</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <table>
        <tr class="top"><td>Saldo Kas Awal Periode</td><td class="value">{{ $rupiah($result['opening_cash']) }}</td></tr>
    </table>

    @foreach ($result['sections'] as $section)
        <table>
            <tr class="section"><td colspan="2">{{ $section['label'] }}</td></tr>
            @forelse ($section['rows'] as $row)
                <tr class="detail">
                    <td>{{ $row['entry_date']->format('d M Y') }} — {{ $row['description'] }} ({{ $row['entry_number'] }})</td>
                    <td class="value">{{ $rupiah($row['amount']) }}</td>
                </tr>
            @empty
                <tr class="detail note"><td colspan="2">Tidak ada arus kas di kategori ini.</td></tr>
            @endforelse
            <tr class="total"><td>Total {{ $section['label'] }}</td><td class="value">{{ $rupiah($section['total']) }}</td></tr>
        </table>
    @endforeach

    <table>
        <tr class="top"><td>Kenaikan (Penurunan) Kas Bersih</td><td class="value">{{ $rupiah($result['net_change']) }}</td></tr>
        <tr class="top"><td>Saldo Kas Akhir Periode</td><td class="value">{{ $rupiah($result['closing_cash']) }}</td></tr>
    </table>

    <p class="note">
        @if ($result['is_reconciled'])
            Sudah sesuai dengan saldo aktual akun kas ({{ $rupiah($result['closing_cash_actual']) }}).
        @else
            Berbeda dari saldo aktual akun kas ({{ $rupiah($result['closing_cash_actual']) }}) — periksa jurnal.
        @endif
    </p>
</body>
</html>
