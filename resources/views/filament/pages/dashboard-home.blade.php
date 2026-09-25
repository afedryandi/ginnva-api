<x-filament-panels::page>
    @php
        $storeOptions = $this->getStoreOptions();
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;
        $canFilterStore = $isFullAccess && count($storeOptions) > 1;
        $storeKey = $storeId ?? 'all';
    @endphp

    {{--
        Filter cabang terpusat (audit Dashboard Utama 2026-09-25, gap
        "standar enterprise dashboard") — SEBELUMNYA tidak ada sama
        sekali di Dashboard Utama, beda dari Dashboard Penjualan yang
        sudah punya ini. Hanya untuk full-access & kalau toko aktif > 1
        (sama syarat dengan SalesDashboard) — staff toko selalu terkunci
        ke tokonya sendiri, dropdown ini tidak pernah muncul untuk mereka.
    --}}
    @if ($canFilterStore)
        <div class="flex flex-wrap items-center gap-3">
            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm dark:border-white/10">
                <x-heroicon-o-building-storefront class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                <select
                    wire:model.live="storeId"
                    class="border-0 bg-transparent p-0 pr-7 text-sm font-medium focus:ring-0 dark:[color-scheme:dark]"
                >
                    <option value="">Semua cabang</option>
                    @foreach ($storeOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                <span wire:loading wire:target="storeId" class="inline-flex items-center text-gray-400 dark:text-gray-500">
                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                </span>
            </label>
        </div>
    @endif

    {{--
        @livewire() langsung per widget (BUKAN <x-filament-widgets::widgets>
        bawaan) — supaya $storeId bisa dikirim ke mount() masing-masing
        (pola sama SalesDashboard.blade.php). canView() tiap widget DICEK
        MANUAL di sini (@livewire tidak otomatis menghormatinya seperti
        komponen bawaan) — kalau lupa ditambahkan di sini, staff yang
        menu_access-nya tidak mencakup modul itu akan tetap melihat
        datanya, jadi setiap widget baru yang didaftarkan ke Dashboard
        Utama WAJIB ditambah blok @if canView() di sini juga.

        Ditumpuk 1 kolom penuh (bukan grid 2 kolom bawaan Filament) —
        StatsOverviewWidget sudah punya grid kartu sendiri di dalamnya,
        menumpuknya 1 kolom lebih aman daripada menebak nilai columnSpan
        default tiap widget (beberapa full/beberapa tidak eksplisit).
    --}}
    <div class="grid grid-cols-1 gap-6">
        @if (\App\Filament\Widgets\PerluPerhatianWidget::canView())
            @livewire(\App\Filament\Widgets\PerluPerhatianWidget::class, ['storeId' => $storeId], key('dashboard-home-perlu-perhatian-' . $storeKey))
        @endif

        @if (\App\Filament\Widgets\BookingRevenueStatsWidget::canView())
            @livewire(\App\Filament\Widgets\BookingRevenueStatsWidget::class, ['storeId' => $storeId], key('dashboard-home-revenue-stats-' . $storeKey))
        @endif

        @if (\App\Filament\Widgets\BookingStatsWidget::canView())
            @livewire(\App\Filament\Widgets\BookingStatsWidget::class, ['storeId' => $storeId], key('dashboard-home-booking-stats-' . $storeKey))
        @endif

        @if (\App\Filament\Widgets\BookingRevenueTrendChart::canView())
            @livewire(\App\Filament\Widgets\BookingRevenueTrendChart::class, ['storeId' => $storeId], key('dashboard-home-revenue-trend-' . $storeKey))
        @endif

        @if (\App\Filament\Widgets\BookingRevenueByCategoryChart::canView())
            @livewire(\App\Filament\Widgets\BookingRevenueByCategoryChart::class, ['storeId' => $storeId], key('dashboard-home-revenue-category-' . $storeKey))
        @endif

        @if (\App\Filament\Widgets\BookingRevenueByPaymentMethodChart::canView())
            @livewire(\App\Filament\Widgets\BookingRevenueByPaymentMethodChart::class, ['storeId' => $storeId], key('dashboard-home-revenue-payment-method-' . $storeKey))
        @endif

        @if (\App\Filament\Widgets\WarrantyTrendChart::canView())
            @livewire(\App\Filament\Widgets\WarrantyTrendChart::class, ['storeId' => $storeId], key('dashboard-home-warranty-trend-' . $storeKey))
        @endif

        {{-- WarrantyByStoreChart SENGAJA tanpa storeId — chart ini memang
             perbandingan ANTAR toko, memfilternya ke 1 toko menghilangkan
             tujuannya sendiri. --}}
        @if (\App\Filament\Widgets\WarrantyByStoreChart::canView())
            @livewire(\App\Filament\Widgets\WarrantyByStoreChart::class, [], key('dashboard-home-warranty-by-store'))
        @endif

        @if (\App\Filament\Widgets\QuotationTrendChart::canView())
            @livewire(\App\Filament\Widgets\QuotationTrendChart::class, ['storeId' => $storeId], key('dashboard-home-quotation-trend-' . $storeKey))
        @endif

        {{-- MarketingStatsWidget SENGAJA tanpa storeId — ProductInquiry/
             PartnershipInquiry tidak punya scoping cabang sama sekali
             (bukan data per-toko), di luar cakupan perbaikan ini. --}}
        @if (\App\Filament\Widgets\MarketingStatsWidget::canView())
            @livewire(\App\Filament\Widgets\MarketingStatsWidget::class, [], key('dashboard-home-marketing-stats'))
        @endif

        @if (\App\Filament\Widgets\KaryawanStatsWidget::canView())
            @livewire(\App\Filament\Widgets\KaryawanStatsWidget::class, ['storeId' => $storeId], key('dashboard-home-karyawan-stats-' . $storeKey))
        @endif

        {{-- MasterDataStatsWidget SENGAJA tanpa storeId — "Toko Aktif" &
             "Produk Film" memang metrik nasional, tidak relevan difilter
             ke 1 cabang. --}}
        @if (\App\Filament\Widgets\MasterDataStatsWidget::canView())
            @livewire(\App\Filament\Widgets\MasterDataStatsWidget::class, [], key('dashboard-home-master-data-stats'))
        @endif

        @if (\App\Filament\Widgets\LayananChart::canView())
            @livewire(\App\Filament\Widgets\LayananChart::class, ['storeId' => $storeId], key('dashboard-home-layanan-chart-' . $storeKey))
        @endif

        {{-- SalesByOutletChart SENGAJA tanpa storeId — sama alasan
             WarrantyByStoreChart, perbandingan antar-outlet. --}}
        @if (\App\Filament\Widgets\SalesByOutletChart::canView())
            @livewire(\App\Filament\Widgets\SalesByOutletChart::class, [], key('dashboard-home-sales-by-outlet'))
        @endif
    </div>
</x-filament-panels::page>
