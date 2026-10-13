<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Top-nav horizontal + cluster (diminta 2026-09-08, referensi Majoo) —
 * setiap grup menu lama (dulu navigationGroup) jadi 1 item di navigasi
 * atas; klik masuk ke cluster-nya menampilkan sub-navigasi (sidebar kiri)
 * berisi resource/page di dalamnya (Quotation, Booking Instalasi, dst).
 * Lihat AdminPanelProvider::panel() untuk ->topNavigation() +
 * ->discoverClusters().
 */
class BookingCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Booking';

    protected static ?int $navigationSort = 10;

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
