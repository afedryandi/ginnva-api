<x-filament-panels::page>
    @include('filament.partials.dashboard-styles')

    {{--
        Dashboard Inventaris (tata letak 2026-12-16), gaya sama dengan Dashboard utama (.gd-): dua blok berlabel dengan jarak lapang.
        "Ringkasan" memuat kartu angka (perlu perhatian, aktivitas, nilai); "Perlu ditinjau" memuat tabel yang urutannya mengikuti jumlah
        baris terbanyak. Blok yang semua widgetnya tersembunyi karena hak akses tidak ditampilkan. Tabelnya lebar, jadi tetap selebar
        halaman, bukan dijepit ke kolom samping.
    --}}
    @php
        $summary = $this->getSummaryWidgets();
        $tables = $this->getTableWidgets();
    @endphp

    <div class="gd">
        @if ($summary !== [])
            <section class="gd-block">
                <div class="gd-label"><x-heroicon-o-squares-2x2 /> Ringkasan</div>
                <x-filament-widgets::widgets :widgets="$summary" :columns="1" />
            </section>
        @endif

        @if ($tables !== [])
            <section class="gd-block">
                <div class="gd-label"><x-heroicon-o-exclamation-triangle /> Perlu ditinjau</div>
                <x-filament-widgets::widgets :widgets="$tables" :columns="1" />
            </section>
        @endif
    </div>
</x-filament-panels::page>
