<?php

namespace App\Filament\Pages;

/**
 * "Laporan Kupon" — diminta 2026-09-09. Sama pola dengan JenisOrderReport/
 * PointReport: extends PromoLoyaltyReport (BUKAN copy-paste). Di Ginnva,
 * "Kupon" = Voucher (kode klaim diskon) -- SAMA PERSIS mekanisme yang
 * dipakai bagian "Promo" di PromoLoyaltyReport, jadi tidak ada data
 * terpisah untuk laporan ini (lihat audit 2026-09-09: Laporan Promo &
 * Laporan Kupon Majoo sama-sama map ke Voucher/VoucherClaim di Ginnva).
 */
class CouponReport extends PromoLoyaltyReport
{
    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Laporan Kupon';

    protected static ?string $title = 'Laporan Kupon';

    // 202 -- band grup 'Laporan Promo & Loyalti' (lihat catatan sistem
    // band di ProductSalesReport.php).
    protected static ?int $navigationSort = 202;
}
