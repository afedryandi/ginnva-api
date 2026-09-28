{{-- Baris laba (Laba Kotor/Operasional/Sebelum Pajak/Bersih) dengan kolom pembanding opsional. --}}
@php($big = $big ?? false)
<div @class([
    'flex justify-between rounded-lg px-3 font-bold',
    'py-2 text-base' => ! $big,
    'py-3 text-lg' => $big,
    'bg-success-50 text-success-700 dark:bg-success-950 dark:text-success-300' => $cur >= 0 && ! $big,
    'bg-danger-50 text-danger-700 dark:bg-danger-950 dark:text-danger-300' => $cur < 0 && ! $big,
    'bg-success-100 text-success-800 dark:bg-success-900 dark:text-success-200' => $cur >= 0 && $big,
    'bg-danger-100 text-danger-800 dark:bg-danger-900 dark:text-danger-200' => $cur < 0 && $big,
])>
    <span>{{ $label }}</span>
    <span class="flex items-baseline gap-4 tabular-nums">
        @if ($prev !== null)
            <span class="text-xs font-normal opacity-70">{{ $rupiah($prev) }}</span>
            <span class="text-xs font-normal">{{ $delta($cur, $prev) }}</span>
        @endif
        <span>{{ $rupiah($cur) }}</span>
        @if (($base ?? null) !== null && abs($base) > 0.005)
            <span class="w-14 text-right text-xs font-normal opacity-70" title="Margin terhadap total pendapatan">{{ number_format($cur / $base * 100, 1, ',', '.') }}%</span>
        @endif
    </span>
</div>
