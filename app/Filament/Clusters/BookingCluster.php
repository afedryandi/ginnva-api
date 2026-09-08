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
}
