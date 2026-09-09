<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * "Laporan Jenis Order" — diminta 2026-09-09 supaya nama ini juga muncul
 * di grup 'Laporan Penjualan' (posisi sub-item ke-7 di Majoo, di dalam
 * kategori "Laporan Penjualan"), TAPI datanya SUDAH SEPENUHNYA tertutupi
 * LayananReport ("Laporan Jasa", grup 'Laporan Jasa' sendiri — split
 * Kaca Film vs PPF, sama persis konsep "jenis order" Majoo). Daripada
 * duplikat query/logic yang sama persis, halaman ini murni SHORTCUT:
 * klik langsung diarahkan ke Laporan Jasa yang sudah ada, satu sumber
 * kebenaran, tidak ada logic/query baru sama sekali — sama pola dengan
 * PersediaanReportRedirect/SettlementReportRedirect.
 */
class JenisOrderReportRedirect extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Laporan Jenis Order';

    protected static ?string $title = 'Laporan Jenis Order';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.redirect-placeholder';

    public static function canAccess(): bool
    {
        return LayananReport::canAccess();
    }

    public function mount(): void
    {
        $this->redirect(LayananReport::getUrl());
    }
}
