<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 14px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        td, th { padding: 4px 8px; border-bottom: 1px solid #e5e7eb; }
        th { font-size: 9px; color: #6b7280; text-align: right; }
        th.label { text-align: left; }
        td.value { text-align: right; white-space: nowrap; }
        td.pct { text-align: right; color: #6b7280; font-size: 9px; white-space: nowrap; }
        tr.top td { font-weight: bold; background: #f3f4f6; }
        tr.section td { font-weight: bold; text-transform: uppercase; font-size: 10px; padding-top: 10px; }
        tr.group td { color: #374151; }
        tr.detail td { padding-left: 24px; color: #6b7280; font-size: 9px; }
        tr.total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        tr.note td { font-style: italic; color: #6b7280; }
        p.note { color: #6b7280; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => ($n < 0 ? '(' : '') . 'Rp' . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : '');
        $compare = $result['compare'] ?? null;
        $showDetails = $result['show_details'] ?? false;
        $delta = function ($cur, $prev) {
            if ($prev === null || abs($prev) < 0.005) {
                return '';
            }

            $p = (($cur - $prev) / abs($prev)) * 100;

            return ($p >= 0 ? '+' : '') . number_format($p, 1, ',', '.') . '%';
        };
        $cols = $compare ? 3 : 1;
        $cells = function ($cur, $prev) use ($rupiah, $delta, $compare) {
            $html = '<td class="value">' . $rupiah($cur) . '</td>';
            if ($compare) {
                $html .= '<td class="value">' . ($prev === null ? '' : $rupiah($prev)) . '</td><td class="pct">' . ($prev === null ? '' : $delta($cur, $prev)) . '</td>';
            }

            return $html;
        };

        $prevGroups = [];
        if ($compare) {
            foreach ($compare['sections'] as $key => $cs) {
                foreach ($cs['groups'] as $g) {
                    $prevGroups[$key][$g['label']] = $g['total'];
                }
            }
        }
    @endphp

    <h1>Laporan Arus Kas</h1>
    <div class="period">
        Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}<br>
        Toko: {{ $result['store_label'] ?? 'Semua Toko' }}
        @if ($compare)
            <br>Pembanding: {{ $result['compare_label'] }}
        @endif
    </div>

    <table>
        @if ($compare)
            <tr><th class="label">Uraian</th><th>Periode Ini</th><th>Pembanding</th><th>Selisih</th></tr>
        @endif
        <tr class="top"><td>Saldo Kas Awal Periode</td>{!! $cells($result['opening_cash'], $compare ? $compare['opening_cash'] : null) !!}</tr>
    </table>

    @foreach ($result['sections'] as $key => $section)
        <table>
            <tr class="section"><td colspan="{{ 1 + $cols }}">{{ $section['label'] }}</td></tr>
            @forelse ($section['groups'] as $group)
                <tr class="group">
                    <td>{{ $group['label'] }} ({{ $group['rows']->count() }} jurnal)</td>
                    {!! $cells($group['total'], $compare ? ($prevGroups[$key][$group['label']] ?? 0) : null) !!}
                </tr>
                @if ($showDetails)
                    @foreach ($group['rows'] as $row)
                        <tr class="detail">
                            <td>{{ $row['entry_date']->format('d M Y') }} — {{ $row['description'] }} ({{ $row['entry_number'] }})</td>
                            <td class="value" colspan="{{ $cols }}">{{ $rupiah($row['amount']) }}</td>
                        </tr>
                    @endforeach
                @endif
            @empty
                <tr class="note"><td colspan="{{ 1 + $cols }}">Tidak ada arus kas di kategori ini.</td></tr>
            @endforelse
            <tr class="total"><td>Total {{ $section['label'] }}</td>{!! $cells($section['total'], $compare ? $compare['sections'][$key]['total'] : null) !!}</tr>
        </table>
    @endforeach

    <table>
        <tr class="top"><td>Kenaikan (Penurunan) Kas Bersih</td>{!! $cells($result['net_change'], $compare ? $compare['net_change'] : null) !!}</tr>
        <tr class="top"><td>Saldo Kas Akhir Periode</td>{!! $cells($result['closing_cash'], $compare ? $compare['closing_cash'] : null) !!}</tr>
    </table>

    @if (! empty($result['cash_accounts']))
        <table>
            <tr><th class="label">Rincian per Akun Kas</th><th>Saldo Awal</th><th>Mutasi</th><th>Saldo Akhir</th></tr>
            @foreach ($result['cash_accounts'] as $ca)
                <tr>
                    <td>{{ $ca['account']->display_name }}</td>
                    <td class="value">{{ $rupiah($ca['opening']) }}</td>
                    <td class="value">{{ $rupiah($ca['mutation']) }}</td>
                    <td class="value">{{ $rupiah($ca['closing']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total</td>
                <td class="value">{{ $rupiah(collect($result['cash_accounts'])->sum('opening')) }}</td>
                <td class="value">{{ $rupiah(collect($result['cash_accounts'])->sum('mutation')) }}</td>
                <td class="value">{{ $rupiah(collect($result['cash_accounts'])->sum('closing')) }}</td>
            </tr>
        </table>
    @endif

    <p class="note">
        @if ($result['is_reconciled'])
            Sudah sesuai dengan saldo aktual akun kas ({{ $rupiah($result['closing_cash_actual']) }}).
        @else
            Berbeda dari saldo aktual akun kas ({{ $rupiah($result['closing_cash_actual']) }}) — periksa jurnal.
        @endif
    </p>

    @if (! empty($result['warnings']))
        <div class="note" style="margin-top: 10px; color: #92400e;">
            <strong>Perlu diperiksa (klasifikasi arus kas):</strong>
            <ul>
                @foreach ($result['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @include('pdf.partials.report-footer')
</body>
</html>
