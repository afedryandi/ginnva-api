<?php

namespace App\Support;

/**
 * Teks tooltip (kursor diarahkan ke badge status / tombol) untuk istilah yang sering membingungkan staf baru (2026-10-12,
 * audit UX "onboarding & bantuan"). Satu tempat supaya mudah diperbarui kalau aturan sistem berubah. Mengembalikan null
 * untuk nilai yang tidak dikenal, jadi status baru tidak memunculkan tooltip yang salah.
 */
class Tooltips
{
    public static function bookingStatus(?string $state): ?string
    {
        return match ($state) {
            'pending'   => 'Booking masuk, belum dikonfirmasi toko. Slot toko belum terkunci. Batas respons (SLA) 4 jam.',
            'confirmed' => 'Sudah dikonfirmasi toko. Slot kapasitas terkunci pada tanggal pengerjaan.',
            'completed' => 'Pengerjaan selesai dan sudah diserahterimakan ke customer.',
            'cancelled' => 'Dibatalkan. Status ini final dan tidak bisa diaktifkan lagi.',
            default     => null,
        };
    }

    public static function bookingSource(?string $state): ?string
    {
        return match ($state) {
            'app'      => 'Pelanggan memesan sendiri lewat aplikasi.',
            'whatsapp' => 'Dibuatkan staff setelah pelanggan menghubungi lewat WhatsApp.',
            'walk_in'  => 'Pelanggan datang langsung ke toko.',
            default    => null,
        };
    }

    public static function warrantyReview(?string $state): ?string
    {
        return match ($state) {
            'pending_review' => 'Menunggu pemeriksaan tim (QA) sebelum garansi berlaku.',
            'approved'       => 'Disetujui QA: garansi berlaku dan pelanggan mendapat poin registrasi garansi.',
            'rejected'       => 'Ditolak QA: garansi tidak berlaku.',
            default          => null,
        };
    }

    public static function warrantyStatus(?string $state): ?string
    {
        return match ($state) {
            'active'         => 'Garansi masih berlaku.',
            'expired'        => 'Masa garansi sudah lewat.',
            'revoked'        => 'Dibatalkan admin: tidak bisa dipakai untuk klaim.',
            'pending_review' => 'Menunggu pemeriksaan tim (QA).',
            default          => null,
        };
    }

    public static function journalStatus(?string $state): ?string
    {
        return match ($state) {
            'draft'  => 'Konsep: belum memengaruhi buku besar dan laporan keuangan.',
            'posted' => 'Sudah dibukukan: masuk ke buku besar dan laporan keuangan.',
            default  => null,
        };
    }

    /** Utang (kewajiban membayar ke pihak lain). */
    public static function payableStatus(?string $state): ?string
    {
        return match ($state) {
            'unpaid'    => 'Utang belum dibayar sama sekali.',
            'partial'   => 'Sebagian utang sudah dibayar; masih ada sisa.',
            'paid'      => 'Utang sudah lunas.',
            'cancelled' => 'Utang dibatalkan.',
            default     => null,
        };
    }

    /** Piutang (tagihan yang akan diterima dari pihak lain). */
    public static function receivableStatus(?string $state): ?string
    {
        return match ($state) {
            'unpaid'    => 'Tagihan belum diterima sama sekali.',
            'partial'   => 'Sebagian tagihan sudah diterima; masih ada sisa.',
            'paid'      => 'Tagihan sudah lunas.',
            'cancelled' => 'Tagihan dibatalkan.',
            default     => null,
        };
    }

    public static function rewardRedemptionStatus(?string $state): ?string
    {
        return match ($state) {
            'pending'   => 'Menunggu diproses staff (reward barang atau layanan).',
            'fulfilled' => 'Sudah diproses. Untuk reward voucher: voucher sudah terbit di akun customer.',
            'cancelled' => 'Dibatalkan: poin pelanggan dikembalikan.',
            default     => null,
        };
    }

    public static function quotationStatus(?string $state): ?string
    {
        return match ($state) {
            'new'       => 'Permintaan penawaran baru masuk, belum dihubungi.',
            'contacted' => 'Sudah dihubungi (follow up) oleh staf.',
            'closed'    => 'Selesai: penawaran ditutup.',
            'cancelled' => 'Permintaan penawaran dibatalkan.',
            default     => null,
        };
    }
}
