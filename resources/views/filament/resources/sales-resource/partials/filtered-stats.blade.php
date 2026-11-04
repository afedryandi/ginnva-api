{{--
    Kartu statistik "Detail Penjualan" — REAKTIF ikut filter tabel (audit 2026-09-11, temuan #3). Dirender lewat Table::header() di
    SalesResource, $stats dihitung dari $livewire->getFilteredTableQuery() (query yang SAMA dipakai tombol Export ke Excel) tiap kali
    tabel re-render (ganti filter/pencarian/halaman).

    Kartu Lunas, Belum Lunas, dan Total Diterima hanya tampil bila modul Keuangan menyala: saat mati, piutang dicatat di Accurate
    (keputusan pemilik 2026-11-04). Tidak ada kartu Void: booking yang dibatalkan tidak mungkin sudah diproses penjualannya.
    Panel memakai CSS Filament yang sudah dikompilasi, jadi layout grid pakai style inline dan warna/angka tabular memakai kelas sd-* di bawah.
--}}
@php
    $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    $financeOn = (bool) config('features.finance');
@endphp

@once
    <style>
        .sd-num { font-variant-numeric: tabular-nums; }
        .sd-up { color: #15803d; } .sd-warn { color: #b45309; }
        .dark .sd-up { color: #4ade80; } .dark .sd-warn { color: #fbbf24; }
    </style>
@endonce

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:0.75rem;">
    <x-filament::section>
        <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan</div>
        <div class="mt-1 text-xl font-bold sd-num">{{ $rupiah($stats['total_revenue']) }}</div>
        <div class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($stats['total_count'], 0, ',', '.') }} transaksi</div>
    </x-filament::section>

    @if ($financeOn)
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Lunas</div>
            <div class="mt-1 text-xl font-bold sd-num sd-up">{{ $rupiah($stats['lunas_amount']) }}</div>
            <div class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($stats['lunas_count'], 0, ',', '.') }} transaksi</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Belum Lunas</div>
            <div @class(['mt-1 text-xl font-bold sd-num', 'sd-warn' => $stats['belum_lunas_count'] > 0])>{{ $rupiah($stats['belum_lunas_amount']) }}</div>
            <div class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($stats['belum_lunas_count'], 0, ',', '.') }} transaksi</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Diterima</div>
            <div class="mt-1 text-xl font-bold sd-num">{{ $rupiah($stats['total_received']) }}</div>
        </x-filament::section>
    @endif
</div>
