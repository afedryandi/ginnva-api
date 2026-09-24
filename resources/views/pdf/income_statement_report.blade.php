<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        td.indent { padding-left: 20px; }
        td.value { text-align: right; }
        tr.section td { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 11px; }
        tr.total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        tr.muted td { color: #9ca3af; font-style: italic; }
        .subtotal { width: 100%; border-collapse: collapse; font-weight: bold; margin-bottom: 14px; }
        .subtotal td { padding: 8px 10px; border: 0; }
        .subtotal td.value { text-align: right; }
        .subtotal.positive td { background: #f0fdf4; color: #15803d; }
        .subtotal.negative td { background: #fef2f2; color: #991b1b; }
        .net { font-size: 14px; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => ($n < 0 ? '(' : '') . 'Rp' . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : '');
        $sections = $result['sections'];
        $renderSection = function (array $section) use ($rupiah) {
            echo '<table><tr class="section"><td colspan="2">' . e($section['label']) . '</td></tr>';
            if (count($section['rows']) === 0) {
                echo '<tr class="muted"><td class="indent">Tidak ada transaksi.</td><td class="value">-</td></tr>';
            }
            foreach ($section['rows'] as $row) {
                echo '<tr><td class="indent">' . e($row['account']->name) . '</td><td class="value">' . $rupiah($row['amount']) . '</td></tr>';
            }
            echo '<tr class="total"><td>Total ' . e($section['label']) . '</td><td class="value">' . $rupiah($section['total']) . '</td></tr>';
            echo '</table>';
        };
        $renderSubtotal = function (string $label, $amount) use ($rupiah) {
            $cls = $amount >= 0 ? 'positive' : 'negative';
            echo '<table class="subtotal ' . $cls . '"><tr><td>' . e($label) . '</td><td class="value">' . $rupiah($amount) . '</td></tr></table>';
        };
    @endphp

    <h1>Laporan Laba Rugi</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    {!! $renderSection($sections['pendapatan']) !!}
    {!! $renderSection($sections['beban_pokok']) !!}
    {!! $renderSubtotal('Laba Kotor', $result['laba_kotor']) !!}

    {!! $renderSection($sections['beban_operasional']) !!}
    {!! $renderSubtotal('Laba Operasional', $result['laba_operasional']) !!}

    {!! $renderSection($sections['pendapatan_lain']) !!}
    {!! $renderSection($sections['beban_lain']) !!}
    {!! $renderSubtotal('Laba Sebelum Pajak', $result['laba_sebelum_pajak']) !!}

    {!! $renderSection($sections['pajak']) !!}
    {!! $renderSubtotal('Laba Bersih', $result['laba_bersih']) !!}
</body>
</html>
