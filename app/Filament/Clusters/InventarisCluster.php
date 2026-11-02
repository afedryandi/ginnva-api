<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Cluster "Inventori" — tab menu atas sendiri, sejajar dengan Booking dan Karyawan (2026-11-02). Stok adalah salah satu dari tiga
 * modul inti, bukan bagian dari grup "Penjualan" (sebelumnya di sana mengikuti struktur Majoo, 2026-09-10).
 *
 * Isi cluster (RawMaterial, ConsumableItem, InventoryItem + riwayat, Aset, Memo Barang, Permohonan Pembelian, Dashboard Inventaris)
 * tidak berubah. Laporan stok (Persediaan, Stok Kedaluwarsa, Serial Number) tetap di klaster Laporan.
 *
 * Urutan tab menu atas: Booking 10, grup Penjualan 14, Inventori 20, Karyawan 30.
 */
class InventarisCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Inventori';

    protected static ?string $clusterBreadcrumb = 'Inventori';

    protected static ?int $navigationSort = 20;

    /**
     * Halaman induk menu hanya bisa dibuka kalau ada isi yang boleh diakses role ini. Tanpa ini, role yang tidak punya
     * satu pun menu di dalamnya (mis. store_manager di Sistem) yang mengetik alamatnya langsung mendapat error 500
     * (tidak ada tujuan alihan), bukan 403.
     */
    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }
}
