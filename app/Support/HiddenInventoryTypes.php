<?php

namespace App\Support;

/**
 * Pilihan jenis barang (consumable_item / asset) yang ikut hilang saat Barang Habis Pakai dan Aset Tetap dimatikan lewat saklar fitur
 * (config/features.php, keputusan 2026-11-13). Dipakai di form Memo, Permohonan Pembelian, Stok Opname, Stok Terbuang, dan laporan stok,
 * supaya tidak ada pilihan yang mengarah ke menu yang tidak tampil.
 */
class HiddenInventoryTypes
{
    /**
     * Buang kunci yang dimatikan dari daftar pilihan (kunci: 'consumable_item', 'asset', atau 'consumable' untuk laporan).
     *
     * @template T
     *
     * @param  array<string, T>  $options
     * @return array<string, T>
     */
    public static function filter(array $options): array
    {
        if (! config('features.consumables')) {
            unset($options['consumable_item'], $options['consumable']);
        }

        if (! config('features.assets')) {
            unset($options['asset']);
        }

        return $options;
    }

    /** Kunci jenis yang masih boleh dipakai, dari daftar penuh. @param list<string> $types @return list<string> */
    public static function allowed(array $types): array
    {
        return array_values(array_filter($types, fn (string $type) => match ($type) {
            'consumable_item', 'consumable' => (bool) config('features.consumables'),
            'asset' => (bool) config('features.assets'),
            default => true,
        }));
    }
}
