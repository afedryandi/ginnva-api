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

    // Dikelompokkan di bawah heading sidebar 'Laporan' (diminta
    // 2026-09-08) -- Dashboard Penjualan (SalesDashboard) SENGAJA tidak
    // ikut, tetap berdiri sendiri di atas grup ini.
    protected static ?string $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Laporan Settlement';

    protected static ?string $title = 'Laporan Settlement';

    protected static ?int $navigationSort = 40;

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
