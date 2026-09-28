<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 14px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th { text-align: right; font-size: 9px; color: #6b7280; padding: 3px 6px; border-bottom: 1px solid #9ca3af; }
        th.label { text-align: left; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        td.indent { padding-left: 18px; }
        td.value { text-align: right; white-space: nowrap; }
        td.pct { text-align: right; color: #6b7280; font-size: 9px; white-space: nowrap; }
        tr.section td { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 10px; }
        tr.group td { color: #6b7280; font-weight: bold; font-size: 9px; padding-top: 6px; }
        tr.subtotal-row td { color: #6b7280; font-style: italic; font-size: 10px; }
        tr.total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        tr.muted td { color: #9ca3af; font-style: italic; }
        tr.profit td { font-weight: bold; padding: 7px 6px; border: 0; }
        tr.profit.positive td { background: #f0fdf4; color: #15803d; }
        tr.profit.negative td { background: #fef2f2; color: #991b1b; }
        tr.profit.net td { font-size: 13px; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => ($n < 0 ? '(' : '') . 'Rp' . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : '');
        $sections = $result['sections'];
        $compare = $result['compare'] ?? null;
        $revenue = (float) $sections['pendapatan']['total'];
        $pct = fn ($n) => abs($revenue) < 0.005 ? '' : number_format($n / $revenue * 100, 1, ',', '.') . '%';
        $delta = function ($cur, $prev) {
            if ($prev === null || abs($prev) < 0.005) {
                return '';
            }

            $p = (($cur - $prev) / abs($prev)) * 100;

            return ($p >= 0 ? '+' : '') . number_format($p, 1, ',', '.') . '%';
        };

        $prevMap = null;
        if ($compare) {
            $prevMap = [];
            foreach ($compare['sections'] as $cs) {
                foreach ($cs['rows'] as $r) {
                    $prevMap[$r['account']->id] = $r['amount'];
                }
            }
        }

        // Kolom: Akun | Periode Ini | % Pendapatan | (Pembanding | Selisih %)
        $cols = $compare ? 5 : 3;

        $head = function () use ($compare) {
            $html = '<tr><th class="label">Akun</th><th>Periode Ini</th><th>% Pendapatan</th>';
            if ($compare) {
                $html .= '<th>Pembanding</th><th>Selisih</th>';
            }

            return $html . '</tr>';
        };

        $cells = function ($cur, $prev) use ($rupiah, $pct, $delta, $compare) {
            $html = '<td class="value">' . $rupiah($cur) . '</td><td class="pct">' . $pct($cur) . '</td>';
            if ($compare) {
                $html .= '<td class="value">' . ($prev === null ? '' : $rupiah($prev)) . '</td><td class="pct">' . ($prev === null ? '' : $delta($cur, $prev)) . '</td>';
            }

            return $html;
        };

        $renderSection = function (array $section, string $type) use ($cells, $prevMap, $compare, $cols) {
            echo '<table><tr class="section"><td colspan="' . $cols . '">' . e($section['label']) . '</td></tr>';

            if (count($section['rows']) === 0) {
                echo '<tr class="muted"><td class="indent" colspan="' . $cols . '">Tidak ada transaksi.</td></tr>';
            }

            $groups = $section['rows']->groupBy(fn ($row) => $row['account']->parent?->name ?? '');
            $showGroups = $groups->count() > 1 || ($groups->keys()->first() ?? '') !== '';

            foreach ($groups as $groupName => $items) {
                if ($showGroups && $groupName !== '') {
                    echo '<tr class="group"><td colspan="' . $cols . '">' . e($groupName) . '</td></tr>';
                }

                foreach ($items as $row) {
                    $prev = $prevMap !== null ? (float) ($prevMap[$row['account']->id] ?? 0) : null;
                    echo '<tr><td class="indent">' . e($row['account']->name) . ($row['account']->is_contra ? ' <small>(pengurang)</small>' : '') . '</td>' . $cells($row['amount'], $prev) . '</tr>';
                }

                if ($showGroups && $groupName !== '' && $items->count() > 1) {
                    echo '<tr class="subtotal-row"><td class="indent">Subtotal ' . e($groupName) . '</td>' . $cells($items->sum('amount'), null) . '</tr>';
                }
            }

            $prevTotal = $compare ? $compare['sections'][$type]['total'] : null;
            echo '<tr class="total"><td>Total ' . e($section['label']) . '</td>' . $cells($section['total'], $prevTotal) . '</tr>';
            echo '</table>';
        };

        $renderProfit = function (string $label, string $key, bool $net = false) use ($result, $compare, $cells, $cols) {
            $cur = $result[$key];
            $cls = ($cur >= 0 ? 'positive' : 'negative') . ($net ? ' net' : '');
            echo '<table><tr class="profit ' . $cls . '"><td>' . e($label) . '</td>' . $cells($cur, $compare ? $compare[$key] : null) . '</tr></table>';
        };
    @endphp

    <h1>Laporan Laba Rugi</h1>
    <div class="period">
        Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}<br>
        Toko: {{ $result['store_label'] ?? 'Semua Toko' }}
        @if ($compare)
            <br>Pembanding: {{ $result['compare_label'] }}
        @endif
    </div>

    <table>{!! $head() !!}</table>

    {!! $renderSection($sections['pendapatan'], 'pendapatan') !!}
    {!! $renderSection($sections['beban_pokok'], 'beban_pokok') !!}
    {!! $renderProfit('Laba Kotor', 'laba_kotor') !!}

    {!! $renderSection($sections['beban_operasional'], 'beban_operasional') !!}
    {!! $renderProfit('Laba Operasional', 'laba_operasional') !!}

    {!! $renderSection($sections['pendapatan_lain'], 'pendapatan_lain') !!}
    {!! $renderSection($sections['beban_lain'], 'beban_lain') !!}
    {!! $renderProfit('Laba Sebelum Pajak', 'laba_sebelum_pajak') !!}

    {!! $renderSection($sections['pajak'], 'pajak') !!}
    {!! $renderProfit('Laba Bersih', 'laba_bersih', true) !!}
    @include('pdf.partials.report-footer')
</body>
</html>
