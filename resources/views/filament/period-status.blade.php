@php($periods = \App\Support\PeriodStatus::summary())
@include('filament.partials.plain-ui')
<div class="pu" style="margin-bottom: 16px;">
    <div class="pu-bar pu-row">
        <span class="pu-strong">Status buku</span>
        @foreach ($periods as $period)
            <span class="pu-row" style="gap: 6px;">
                <span class="pu-muted">{{ $period['label'] }}:</span>
                @if ($period['closed'])
                    <x-filament::badge color="gray" size="sm">Ditutup</x-filament::badge>
                    @if ($period['closed_by'] && $period['closed_at'])
                        <span class="pu-muted">oleh {{ $period['closed_by'] }}, {{ $period['closed_at']->copy()->locale('id')->translatedFormat('j M Y') }}</span>
                    @endif
                @else
                    <x-filament::badge color="success" size="sm">Terbuka</x-filament::badge>
                @endif
            </span>
        @endforeach
        <span class="pu-muted">Transaksi tidak bisa bertanggal di bulan yang ditutup.</span>
    </div>
</div>
