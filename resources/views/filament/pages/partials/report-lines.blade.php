{{--
    Baris akun untuk Laba Rugi & Neraca (audit Laporan Keuangan 2026-09-29): dikelompokkan per akun
    induk dengan subtotal, akun kontra diberi label "(pengurang)", nama akun bisa diklik (drill-down ke
    Buku Besar), dan kolom pembanding + selisih % kalau $prevMap diberikan.

    Parameter: $rows (collection of ['account' => ChartOfAccount, $key => float]), $key ('amount'|'balance'),
    $rupiah (closure), $prevMap (?array id => nilai pembanding), $drill (?closure account => url)
--}}
@php
    $groups = $rows->groupBy(fn ($r) => $r['account']->parent?->name ?? '');
    $showGroups = $groups->count() > 1 || ($groups->keys()->first() ?? '') !== '';
    $delta = function ($cur, $prev) {
        if ($prev === null || abs($prev) < 0.005) {
            return '—';
        }

        $pct = (($cur - $prev) / abs($prev)) * 100;

        return ($pct >= 0 ? '+' : '') . number_format($pct, 1, ',', '.') . '%';
    };
@endphp

@foreach ($groups as $groupName => $items)
    @if ($showGroups && $groupName !== '')
        <div class="mt-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $groupName }}</div>
    @endif

    @foreach ($items as $row)
        @php
            $acc = $row['account'];
            $amt = $row[$key];
            $prev = $prevMap !== null ? (float) ($prevMap[$acc->id] ?? 0) : null;
        @endphp
        <div class="flex items-baseline justify-between gap-3 border-b border-gray-100 py-1.5 pl-3 text-sm dark:border-white/5">
            <span class="text-gray-600 dark:text-gray-300">
                @if ($drill)
                    <a href="{{ $drill($acc) }}" class="hover:underline" title="Lihat Buku Besar akun ini">{{ $acc->name }}</a>
                @else
                    {{ $acc->name }}
                @endif
                @if ($acc->is_contra)
                    <span class="text-xs text-gray-400">(pengurang)</span>
                @endif
            </span>
            <span class="flex items-baseline gap-4 tabular-nums">
                @if ($prev !== null)
                    <span class="text-xs text-gray-400">{{ $rupiah($prev) }}</span>
                    <span @class(['text-xs', 'text-success-600' => ($amt - $prev) >= 0, 'text-danger-600' => ($amt - $prev) < 0])>{{ $delta($amt, $prev) }}</span>
                @endif
                <span>{{ $rupiah($amt) }}</span>
                @if (($base ?? null) !== null && abs($base) > 0.005)
                    <span class="w-14 text-right text-xs text-gray-400" title="Persentase terhadap total pendapatan">{{ number_format($amt / $base * 100, 1, ',', '.') }}%</span>
                @endif
            </span>
        </div>
    @endforeach

    @if ($showGroups && $groupName !== '' && $items->count() > 1)
        <div class="flex justify-between py-1 pl-3 text-xs font-medium text-gray-500 dark:text-gray-400">
            <span>Subtotal {{ $groupName }}</span>
            <span class="tabular-nums">{{ $rupiah($items->sum($key)) }}</span>
        </div>
    @endif
@endforeach
