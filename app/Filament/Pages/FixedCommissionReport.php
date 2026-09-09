<?php

namespace App\Filament\Pages;

/**
 * "Komisi Tetap" — diminta 2026-09-09. Sama pola dengan JenisOrderReport/
 * PointReport: extends TechnicianCommissionReport (BUKAN copy-paste).
 * Aturan komisi teknisi Ginnva yang dikonfirmasi user (2026-09-09,
 * saat TechnicianCommissionReport pertama dibangun) MEMANG nominal
 * TETAP per pekerjaan -- persis definisi "Komisi Tetap" Majoo, jadi
 * tidak ada logic terpisah yang perlu dibuat, cuma nama menu yang
 * disamakan.
 *
 * "Komisi Bertingkat" Majoo (rate berjenjang berdasarkan volume/tier)
 * TIDAK dibuatkan halaman -- Technician.commission_amount cuma 1
 * angka tetap per teknisi, tidak ada struktur tingkatan sama sekali.
 */
class FixedCommissionReport extends TechnicianCommissionReport
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Komisi Tetap';

    protected static ?string $title = 'Komisi Tetap';

    // 403 -- band grup 'Laporan Karyawan' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 403;
}
