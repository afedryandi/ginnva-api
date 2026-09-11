{{--
    Kartu statistik "Detail Penjualan" — REAKTIF ikut filter tabel (audit
    2026-09-11, temuan #3). Dirender lewat Table::header() di
    SalesResource, $stats dihitung dari $livewire->getFilteredTableQuery()
    (query yang SAMA dipakai tombol Export ke Excel) tiap kali tabel
    re-render (ganti filter/pencarian/halaman) — BUKAN header widget
    statis seperti sebelumnya yang selalu tampilkan total keseluruhan
    data tanpa peduli filter apa pun yang dipilih.
--}}
@php
    $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
@endphp

<div class="grid grid-cols-2 gap-3 md:grid-cols-5">
    <x-filament::section>
        <div class="text-xs text-gray-500 dark:text-gray-400">Total Invoice</div>
        <div class="mt-1 text-xl font-bold tabular-nums">{{ $rupiah($stats['total_revenue']) }}</div>
        <div class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($stats['total_count'], 0, ',', '.') }} invoice</div>
    </x-filament::section>

    <x-filament::section>
        <div class="text-xs text-gray-500 dark:text-gray-400">Lunas</div>
        <div class="mt-1 text-xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($stats['lunas_amount']) }}</div>
        <div class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($stats['lunas_count'], 0, ',', '.') }} invoice</div>
    </x-filament::section>

    <x-filament::section>
        <div class="text-xs text-gray-500 dark:text-gray-400">Belum Lunas</div>
        <div @class([
            'mt-1 text-xl font-bold tabular-nums',
            'text-warning-600 dark:text-warning-400' => $stats['belum_lunas_count'] > 0,
        ])>{{ $rupiah($stats['belum_lunas_amount']) }}</div>
        <div class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($stats['belum_lunas_count'], 0, ',', '.') }} invoice</div>
    </x-filament::section>

    <x-filament::section>
        <div class="text-xs text-gray-500 dark:text-gray-400">Void</div>
        <div @class([
            'mt-1 text-xl font-bold tabular-nums',
            'text-danger-600 dark:text-danger-400' => $stats['void_count'] > 0,
        ])>{{ $rupiah($stats['void_amount']) }}</div>
        <div class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($stats['void_count'], 0, ',', '.') }} invoice</div>
    </x-filament::section>

    <x-filament::section>
        <div class="text-xs text-gray-500 dark:text-gray-400">Total Diterima</div>
        <div class="mt-1 text-xl font-bold tabular-nums">{{ $rupiah($stats['total_received']) }}</div>
    </x-filament::section>
</div>
