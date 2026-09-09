<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * "Laporan Persediaan" — diminta 2026-09-08 supaya nama ini muncul di
 * tab Penjualan (sama penamaan Majoo), TAPI datanya SUDAH SEPENUHNYA
 * tertutupi InventoryDashboard (Dashboard Inventaris di cluster
 * Inventaris) + 3 resource Riwayat (Bahan Baku/Barang Habis Pakai/
 * Keluar-Masuk) — bukan cuma setara, malah lebih detail. Daripada
 * duplikat widget yang sama persis (beda dari SalesDashboard yang
 * memang sengaja duplikat widget PENDAPATAN karena itu domain
 * Penjualan asli), halaman ini murni SHORTCUT: klik langsung diarahkan
 * ke Dashboard Inventaris yang sudah ada, satu sumber kebenaran, tidak
 * ada logic/query baru sama sekali.
 */
class PersediaanReportRedirect extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Grup sendiri 'Laporan Persediaan' (diubah 2026-09-09 dari
    // 'Laporan' gabungan) -- sejajar dengan grup kategori laporan lain.
    protected static ?string $navigationGroup = 'Laporan Persediaan';

    protected static ?string $navigationLabel = 'Laporan Persediaan';

    protected static ?string $title = 'Laporan Persediaan';

    // 500 -- band grup 'Laporan Persediaan' (lihat catatan sistem band
    // di ProductSalesReport.php, diperbaiki 2026-09-09).
    protected static ?int $navigationSort = 500;

    protected static string $view = 'filament.pages.redirect-placeholder';

    public static function canAccess(): bool
    {
        return InventoryDashboard::canAccess();
    }

    public function mount(): void
    {
        $this->redirect(InventoryDashboard::getUrl());
    }
}
