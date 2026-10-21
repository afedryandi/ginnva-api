<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Perlindungan hapus untuk master stok (Bahan Baku, Barang Habis Pakai), Master Data 2026-10-21. Riwayat pergerakan stok memakai
 * cascadeOnDelete di database, jadi menghapus bahan/barang yang punya riwayat menghapus diam-diam seluruh buku stoknya dan merusak
 * laporan persediaan serta HPP. Yang punya riwayat tidak boleh dihapus; yang belum pernah bergerak (salah ketik) boleh.
 */
class StockMasterDeletion
{
    /** Alasan (siap tampil) bila tidak boleh dihapus, null bila boleh. Model harus punya relasi movements(). */
    public static function blocker(Model $record, string $noun): ?string
    {
        $count = $record->movements()->count();

        if ($count === 0) {
            return null;
        }

        return "{$noun} ini punya {$count} riwayat pergerakan stok. Menghapusnya akan ikut menghapus seluruh riwayat itu dan merusak laporan persediaan dan HPP. "
            . 'Kalau sudah tidak dipakai, kosongkan stoknya lewat Catat Stok / Sesuaikan Stok dan biarkan terdaftar.';
    }
}
