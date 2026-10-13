<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class MarketingKontenCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Marketing/Konten';

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
