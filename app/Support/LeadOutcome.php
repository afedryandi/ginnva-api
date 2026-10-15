<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Hasil lead (Marketing/Konten Fase 1, 2026-10-23): menang atau kalah beserta alasan kalah, dan penyelarasan kolom turunannya.
 * Dipakai tiga model lead (Quotation, ProductInquiry, PartnershipInquiry) lewat observer masing-masing.
 */
class LeadOutcome
{
    public const WON = 'won';

    public const LOST = 'lost';

    public const OUTCOMES = [
        self::WON => 'Menang (jadi pelanggan/booking/partner)',
        self::LOST => 'Kalah',
    ];

    /** Alasan kalah baku (kunci => label), supaya laporan bisa mengelompokkan. */
    public const REASONS = [
        'harga' => 'Harga di atas anggaran',
        'beli_di_tempat_lain' => 'Memilih penyedia lain',
        'tidak_merespons' => 'Tidak merespons lagi',
        'belum_butuh' => 'Belum butuh / menunda',
        'lokasi_jauh' => 'Lokasi terlalu jauh',
        'tidak_sesuai_kebutuhan' => 'Layanan tidak sesuai kebutuhan',
        'lainnya' => 'Lainnya',
    ];

    /**
     * Menyelaraskan kolom turunan sebelum disimpan: outcome_at terisi saat hasil ditetapkan (dan dikosongkan saat hasil dihapus),
     * alasan hanya berlaku untuk "kalah".
     */
    public static function sync(Model $lead): void
    {
        if (! $lead->isDirty('outcome') && ! $lead->isDirty('outcome_reason')) {
            return;
        }

        if (blank($lead->outcome)) {
            $lead->outcome = null;
            $lead->outcome_reason = null;
            $lead->outcome_at = null;

            return;
        }

        if ($lead->outcome !== self::LOST) {
            $lead->outcome_reason = null;
        }

        if ($lead->isDirty('outcome') || empty($lead->outcome_at)) {
            $lead->outcome_at = now();
        }
    }
}
