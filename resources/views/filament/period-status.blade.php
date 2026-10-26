@php($periods = \App\Support\PeriodStatus::summary())
<div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-white/10 dark:bg-gray-900">
    <span class="font-semibold text-gray-700 dark:text-gray-300">Status buku</span>
    @foreach ($periods as $period)
        <span class="inline-flex items-center gap-1.5">
            <span class="text-gray-600 dark:text-gray-400">{{ $period['label'] }}:</span>
            @if ($period['closed'])
                <x-filament::badge color="gray" size="sm">Ditutup</x-filament::badge>
                @if ($period['closed_by'] && $period['closed_at'])
                    <span class="text-gray-500 dark:text-gray-400">oleh {{ $period['closed_by'] }}, {{ $period['closed_at']->translatedFormat('j M Y') }}</span>
                @endif
            @else
                <x-filament::badge color="success" size="sm">Terbuka</x-filament::badge>
            @endif
        </span>
    @endforeach
    <span class="text-gray-500 dark:text-gray-400">Transaksi tidak bisa bertanggal di bulan yang ditutup.</span>
</div>
