<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class KeuanganCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Keuangan';

    protected static ?int $navigationSort = 50;

    /**
     * Halaman induk menu hanya bisa dibuka kalau ada isi yang boleh diakses role ini. Tanpa ini, role yang tidak punya
     * satu pun menu di dalamnya (mis. store_manager di Sistem) yang mengetik alamatnya langsung mendapat error 500
     * (tidak ada tujuan alihan), bukan 403.
     */
    public static function canAccess(): bool
    {
        if (! config('features.finance')) {
            return false;
        }

        return static::canAccessClusteredComponents();
    }
}
