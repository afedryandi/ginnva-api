<x-filament-panels::page>
    @php
        $storeOptions = $this->getStoreOptions();
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;
        $canFilterStore = $isFullAccess && count($storeOptions) > 1;
        $storeKey = $storeId ?? 'all';
    @endphp

    @include('filament.partials.dashboard-styles')

    {{--
        Dashboard (tata letak 2026-12-16): kolom utama 2/3 berisi Ringkasan, Tren penjualan, Garansi dan prospek, Tim dan data; kolom samping 1/3
        berisi Perlu perhatian, status cadangan, dan Aktivitas terbaru, jadi hal yang perlu ditindak selalu terlihat di samping angka. Di layar
        sempit kolom samping naik ke atas. Tiap widget tetap dirender lewat @livewire() dengan canView() dicek manual (hak akses menu per staf
        dihormati persis seperti sebelumnya) dan storeId dikirim ke mount() widget yang memang punya filter toko. Kolom atau blok yang semua
        widgetnya tersembunyi karena hak akses tidak ditampilkan sama sekali. Judul dan sapaan ada di kepala halaman (DashboardHome::getHeading).
    --}}
    @php
        $show = fn (string $widget) => $widget::canView();
        $attention = $show(\App\Filament\Widgets\PerluPerhatianWidget::class);
        $activity = $show(\App\Filament\Widgets\AktivitasTerbaruWidget::class);
        $backup = $show(\App\Filament\Widgets\BackupStatusWidget::class);
        $revenue = $show(\App\Filament\Widgets\BookingRevenueStatsWidget::class);
        $bookings = $show(\App\Filament\Widgets\BookingStatsWidget::class);
        $trend = $show(\App\Filament\Widgets\BookingRevenueTrendChart::class);
        $category = $show(\App\Filament\Widgets\BookingRevenueByCategoryChart::class);
        $payment = $show(\App\Filament\Widgets\BookingRevenueByPaymentMethodChart::class);
        $layanan = $show(\App\Filament\Widgets\LayananChart::class);
        $outlet = $show(\App\Filament\Widgets\SalesByOutletChart::class);
        $warrantyTrend = $show(\App\Filament\Widgets\WarrantyTrendChart::class);
        $warrantyStore = $show(\App\Filament\Widgets\WarrantyByStoreChart::class);
        $quotation = $show(\App\Filament\Widgets\QuotationTrendChart::class);
        $marketing = $show(\App\Filament\Widgets\MarketingStatsWidget::class);
        $karyawan = $show(\App\Filament\Widgets\KaryawanStatsWidget::class);
        $masterData = $show(\App\Filament\Widgets\MasterDataStatsWidget::class);

        $hasSide = $attention || $backup || $activity;
        $hasMain = $revenue || $bookings || $trend || $category || $payment || $layanan || $outlet || $warrantyTrend || $warrantyStore || $quotation || $marketing || $karyawan || $masterData;
    @endphp

    <div class="gd">
        {{-- Filter toko (hanya untuk akses penuh dengan lebih dari satu toko aktif), rata kanan di bawah judul halaman. --}}
        @if ($canFilterStore)
            <div class="gd-toolbar">
                <label class="gd-filter">
                    <x-heroicon-o-building-storefront />
                    <select wire:model.live="storeId" aria-label="Filter toko">
                        <option value="">Semua toko</option>
                        @foreach ($storeOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <span wire:loading wire:target="storeId" class="gd-spin" aria-hidden="true">
                        <svg style="width: .875rem; height: .875rem; animation: spin 1s linear infinite;" viewBox="0 0 24 24" fill="none">
                            <circle style="opacity: .25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path style="opacity: .75;" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                    </span>
                </label>
            </div>
        @endif

        <div class="gd-layout {{ $hasSide && $hasMain ? 'gd-layout--side' : '' }}">
            @if ($hasMain)
                <div class="gd-col">
                    @if ($revenue || $bookings)
                        <section class="gd-block">
                            <div class="gd-label"><x-heroicon-o-squares-2x2 /> Ringkasan</div>
                            @if ($revenue)
                                @livewire(\App\Filament\Widgets\BookingRevenueStatsWidget::class, ['storeId' => $storeId], key('dashboard-home-revenue-stats-' . $storeKey))
                            @endif
                            @if ($bookings)
                                @livewire(\App\Filament\Widgets\BookingStatsWidget::class, ['storeId' => $storeId], key('dashboard-home-booking-stats-' . $storeKey))
                            @endif
                        </section>
                    @endif

                    @if ($trend || $category || $payment || $layanan || $outlet)
                        <section class="gd-block">
                            <div class="gd-label"><x-heroicon-o-chart-bar /> Tren penjualan</div>
                            @if ($trend)
                                @livewire(\App\Filament\Widgets\BookingRevenueTrendChart::class, ['storeId' => $storeId], key('dashboard-home-revenue-trend-' . $storeKey))
                            @endif
                            @if ($category || $payment)
                                <div class="gd-grid gd-grid--2">
                                    @if ($category)
                                        @livewire(\App\Filament\Widgets\BookingRevenueByCategoryChart::class, ['storeId' => $storeId], key('dashboard-home-revenue-category-' . $storeKey))
                                    @endif
                                    @if ($payment)
                                        @livewire(\App\Filament\Widgets\BookingRevenueByPaymentMethodChart::class, ['storeId' => $storeId], key('dashboard-home-revenue-payment-method-' . $storeKey))
                                    @endif
                                </div>
                            @endif
                            @if ($layanan)
                                @livewire(\App\Filament\Widgets\LayananChart::class, ['storeId' => $storeId], key('dashboard-home-layanan-chart-' . $storeKey))
                            @endif
                            {{-- SalesByOutletChart SENGAJA tanpa storeId: perbandingan antar-outlet. --}}
                            @if ($outlet)
                                @livewire(\App\Filament\Widgets\SalesByOutletChart::class, [], key('dashboard-home-sales-by-outlet'))
                            @endif
                        </section>
                    @endif

                    @if ($warrantyTrend || $warrantyStore || $quotation || $marketing)
                        <section class="gd-block">
                            <div class="gd-label"><x-heroicon-o-shield-check /> Garansi dan prospek</div>
                            @if ($warrantyTrend || $quotation)
                                <div class="gd-grid gd-grid--2">
                                    @if ($warrantyTrend)
                                        @livewire(\App\Filament\Widgets\WarrantyTrendChart::class, ['storeId' => $storeId], key('dashboard-home-warranty-trend-' . $storeKey))
                                    @endif
                                    @if ($quotation)
                                        @livewire(\App\Filament\Widgets\QuotationTrendChart::class, ['storeId' => $storeId], key('dashboard-home-quotation-trend-' . $storeKey))
                                    @endif
                                </div>
                            @endif
                            {{-- WarrantyByStoreChart dan MarketingStatsWidget SENGAJA tanpa storeId: perbandingan antar-toko / data tanpa cabang. --}}
                            @if ($warrantyStore)
                                @livewire(\App\Filament\Widgets\WarrantyByStoreChart::class, [], key('dashboard-home-warranty-by-store'))
                            @endif
                            @if ($marketing)
                                @livewire(\App\Filament\Widgets\MarketingStatsWidget::class, [], key('dashboard-home-marketing-stats'))
                            @endif
                        </section>
                    @endif

                    @if ($karyawan || $masterData)
                        <section class="gd-block">
                            <div class="gd-label"><x-heroicon-o-users /> Tim dan data</div>
                            @if ($karyawan)
                                @livewire(\App\Filament\Widgets\KaryawanStatsWidget::class, ['storeId' => $storeId], key('dashboard-home-karyawan-stats-' . $storeKey))
                            @endif
                            {{-- MasterDataStatsWidget SENGAJA tanpa storeId: metrik nasional. --}}
                            @if ($masterData)
                                @livewire(\App\Filament\Widgets\MasterDataStatsWidget::class, [], key('dashboard-home-master-data-stats'))
                            @endif
                        </section>
                    @endif
                </div>
            @endif

            @if ($hasSide)
                <aside class="gd-col gd-col--side">
                    <section class="gd-block">
                        <div class="gd-label"><x-heroicon-o-bell-alert /> Perlu perhatian{{ $activity ? ' dan aktivitas' : '' }}</div>
                        @if ($attention)
                            @livewire(\App\Filament\Widgets\PerluPerhatianWidget::class, ['storeId' => $storeId], key('dashboard-home-perlu-perhatian-' . $storeKey))
                        @endif
                        @if ($backup)
                            @livewire(\App\Filament\Widgets\BackupStatusWidget::class, [], key('dashboard-home-backup-status'))
                        @endif
                        {{-- Aktivitas terbaru: log asli (tanpa filter toko), hanya untuk yang boleh membuka Histori Aktivitas. --}}
                        @if ($activity)
                            @livewire(\App\Filament\Widgets\AktivitasTerbaruWidget::class, [], key('dashboard-home-aktivitas-terbaru'))
                        @endif
                    </section>
                </aside>
            @endif
        </div>
    </div>
</x-filament-panels::page>
