<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BankStatementLineResource;
use Filament\Pages\Page;

/**
 * "Laporan Settlement" — diminta 2026-09-08 supaya nama ini muncul di
 * tab Penjualan (sama penamaan Majoo), TAPI konsepnya SUDAH tertutupi
 * BankStatementLineResource ("Rekonsiliasi Bank", cluster Keuangan) —
 * mencocokkan baris mutasi rekening (termasuk settlement payment
 * gateway/EDC) dengan transaksi yang tercatat di sistem. Halaman ini
 * murni SHORTCUT: klik langsung diarahkan ke Rekonsiliasi Bank yang
 * sudah ada, satu sumber kebenaran, tidak ada logic/query baru sama
 * sekali.
 */
class SettlementReportRedirect extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Grup sendiri 'Laporan Settlement' (diubah 2026-09-09 dari
    // 'Laporan' gabungan) -- sejajar dengan grup kategori laporan lain.
    protected static ?string $navigationGroup = 'Laporan Settlement';

    protected static ?string $navigationLabel = 'Laporan Settlement';

    protected static ?string $title = 'Laporan Settlement';

    // 600 -- band grup 'Laporan Settlement' (lihat catatan sistem band
    // di ProductSalesReport.php, diperbaiki 2026-09-09).
    protected static ?int $navigationSort = 600;

    protected static string $view = 'filament.pages.redirect-placeholder';

    public static function canAccess(): bool
    {
        return BankStatementLineResource::canViewAny();
    }

    public function mount(): void
    {
        $this->redirect(BankStatementLineResource::getUrl('index'));
    }
}
