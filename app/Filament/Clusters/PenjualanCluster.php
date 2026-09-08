<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Tab top-nav terpisah khusus "Penjualan" — diminta 2026-09-08.
 * SEBELUMNYA SalesResource ditaruh sebagai sub-kategori sidebar di
 * dalam cluster Booking (dibahas & disepakati saat itu supaya tidak
 * menambah lebar top-nav) — sekarang diminta jadi tab sendiri, jadi
 * dipindah ke sini. Booking TETAP jadi cluster sendiri untuk sisi
 * siklus lead->jadwal->kerjakan->garansi (lihat BookingCluster).
 */
class PenjualanCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Penjualan';

    protected static ?int $navigationSort = 15;
}
