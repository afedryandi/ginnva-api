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
        tr.grand-total td { font-weight: bold; border-top: 2px solid #6b7280; background: #f9fafb; }
        tr.muted td { color: #9ca3af; font-style: italic; }
        .status { padding: 8px 10px; margin-top: 8px; font-weight: bold; }
        .status.balanced { background: #f0fdf4; color: #15803d; }
        .status.unbalanced { background: #fef2f2; color: #991b1b; }
        .ratios td { border: 0; padding: 2px 6px; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => ((float) $n < 0 ? '(' : '') . 'Rp' . number_format(abs((float) $n), 0, ',', '.') . ((float) $n < 0 ? ')' : '');
        $compare = $result['compare'] ?? null;
        $base = (float) $result['aset']['total'];
        $pct = fn ($n) => abs($base) < 0.005 ? '' : number_format($n / $base * 100, 1, ',', '.') . '%';
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
            foreach (['aset', 'kewajiban', 'modal'] as $g) {
                foreach ($compare[$g]['rows'] as $r) {
                    $prevMap[$r['account']->id] = $r['balance'];
                }
            }
        }

        $cols = $compare ? 5 : 3;

        $cells = function ($cur, $prev) use ($rupiah, $pct, $delta, $compare) {
            $html = '<td class="value">' . $rupiah($cur) . '</td><td class="pct">' . $pct($cur) . '</td>';
            if ($compare) {
                $html .= '<td class="value">' . ($prev === null ? '' : $rupiah($prev)) . '</td><td class="pct">' . ($prev === null ? '' : $delta($cur, $prev)) . '</td>';
            }

            return $html;
        };

        $renderGroup = function (string $key, string $title, string $emptyText) use ($result, $cells, $prevMap, $cols) {
            echo '<tr class="section"><td colspan="' . $cols . '">' . e($title) . '</td></tr>';

            if ($result[$key]['rows']->isEmpty()) {
                echo '<tr class="muted"><td class="indent" colspan="' . $cols . '">' . e($emptyText) . '</td></tr>';

                return;
            }

            $groups = $result[$key]['rows']->groupBy(fn ($row) => $row['account']->parent?->name ?? '');
            $showGroups = $groups->count() > 1 || ($groups->keys()->first() ?? '') !== '';

            foreach ($groups as $groupName => $items) {
                if ($showGroups && $groupName !== '') {
                    echo '<tr class="group"><td colspan="' . $cols . '">' . e($groupName) . '</td></tr>';
                }

                foreach ($items as $row) {
                    $prev = $prevMap !== null ? (float) ($prevMap[$row['account']->id] ?? 0) : null;
                    echo '<tr><td class="indent">' . e($row['account']->name) . ($row['account']->is_contra ? ' <small>(pengurang)</small>' : '') . '</td>' . $cells($row['balance'], $prev) . '</tr>';
                }

                if ($showGroups && $groupName !== '' && $items->count() > 1) {
                    echo '<tr class="subtotal-row"><td class="indent">Subtotal ' . e($groupName) . '</td>' . $cells($items->sum('balance'), null) . '</tr>';
                }
            }
        };

        $totalRow = function (string $label, $cur, $prev, string $class = 'total') use ($cells) {
            echo '<tr class="' . $class . '"><td>' . e($label) . '</td>' . $cells($cur, $prev) . '</tr>';
        };
    @endphp

    <h1>Neraca (Balance Sheet)</h1>
    <div class="period">
        Per Tanggal: {{ $result['as_of']->format('d M Y') }}<br>
        Toko: {{ $result['store_label'] ?? 'Semua Toko' }}
        @if ($compare)
            <br>Pembanding: {{ $result['compare_label'] }}
        @endif
    </div>

    <table>
        <tr>
            <th class="label">Akun</th><th>Saldo</th><th>% Total Aset</th>
            @if ($compare)
                <th>Pembanding</th><th>Selisih</th>
            @endif
        </tr>

        {!! $renderGroup('aset', 'Aset', 'Belum ada saldo aset.') !!}
        {!! $totalRow('Total Aset', $result['aset']['total'], $compare ? $compare['aset']['total'] : null) !!}

        {!! $renderGroup('kewajiban', 'Kewajiban', 'Belum ada saldo kewajiban.') !!}
        {!! $totalRow('Total Kewajiban', $result['kewajiban']['total'], $compare ? $compare['kewajiban']['total'] : null) !!}

        {!! $renderGroup('modal', 'Modal', 'Belum ada saldo modal.') !!}
        @if (abs($result['modal']['laba_tahun_lalu']) >= 0.005)
            <tr><td class="indent">Laba (Rugi) Tahun-Tahun Sebelumnya (belum ditutup)</td>{!! $cells($result['modal']['laba_tahun_lalu'], null) !!}</tr>
        @endif
        <tr><td class="indent">Laba (Rugi) Tahun Berjalan</td>{!! $cells($result['modal']['laba_tahun_berjalan'], null) !!}</tr>
        {!! $totalRow('Total Modal', $result['modal']['total'], $compare ? $compare['modal']['total'] : null) !!}
        {!! $totalRow('Total Kewajiban + Modal', $result['total_kewajiban_modal'], $compare ? $compare['total_kewajiban_modal'] : null, 'grand-total') !!}
    </table>

    @if ($result['is_balanced'])
        <div class="status balanced">Balance — Total Aset ({{ $rupiah($result['aset']['total']) }}) sama dengan Total Kewajiban + Modal.</div>
    @else
        <div class="status unbalanced">TIDAK balance — Total Aset ({{ $rupiah($result['aset']['total']) }}) berbeda dari Total Kewajiban + Modal ({{ $rupiah($result['total_kewajiban_modal']) }}). Periksa jurnal yang mungkin belum lengkap.</div>
    @endif

    @if (! empty($result['ratios']))
        @php($x = $result['ratios'])
        <table class="ratios" style="margin-top: 12px;">
            <tr><td colspan="2"><strong>Rasio Keuangan</strong></td></tr>
            <tr><td>Rasio Lancar (Aset Lancar / Kewajiban Lancar)</td><td class="value">{{ $x['current_ratio'] === null ? '-' : number_format($x['current_ratio'], 2, ',', '.') . 'x' }}</td></tr>
            <tr><td>Modal Kerja (Aset Lancar − Kewajiban Lancar)</td><td class="value">{{ $rupiah($x['working_capital']) }}</td></tr>
            <tr><td>Kewajiban terhadap Modal</td><td class="value">{{ $x['debt_to_equity'] === null ? '-' : number_format($x['debt_to_equity'], 2, ',', '.') . 'x' }}</td></tr>
            <tr><td>Kewajiban terhadap Aset</td><td class="value">{{ $x['debt_to_assets'] === null ? '-' : number_format($x['debt_to_assets'], 1, ',', '.') . '%' }}</td></tr>
        </table>
    @endif
    @include('pdf.partials.report-footer')
</body>
</html>
