<?php

namespace App\Services;

use App\Models\FilmProduct;
use App\Models\PriceRule;

/**
 * Kalkulasi harga jual produk = FilmProduct.base_price × koefisien
 * PriceRule(ukuran kendaraan, bagian mobil).
 *
 * Rancangan lama Ginnva (base_price × coefficient) yang baru diaktifkan
 * 2026-09-10 — FASE 1: mesin + tampilan simulasi di Filament + menu
 * "Koefisien Harga" dibuka lagi. Wiring ke alur quotation (auto-hitung
 * saat customer/staff bikin lead) = Fase 2, belum dikerjakan.
 *
 * Selama base_price produk masih Rp 0 (nunggu daftar harga kantor
 * pusat), calculate() mengembalikan filled=false dan UI menampilkan
 * "Belum diisi" — BUKAN Rp 0 palsu.
 */
class PriceCalculator
{
    public const VEHICLE_SIZES = ['S', 'M', 'L', 'XL', 'XXL'];

    /**
     * Bagian mobil yang dipakai untuk cari koefisien, diturunkan dari
     * jenis & posisi produk:
     *   - PPF                      -> 'full_set' (pelindung body, dijual
     *                                 per ukuran mobil)
     *   - Window film 'front'      -> 'front'
     *   - Window film 'side_rear'  -> 'side'
     *   - Window film 'all'/lain   -> 'full_set'
     *
     * ASUMSI awal — hubungan posisi<->bagian mobil belum dikonfirmasi
     * user; sesuaikan di sini kalau ternyata beda (mis. 'side_rear'
     * mestinya gabungan 'side' + 'back').
     */
    public static function carPartFor(FilmProduct $product): string
    {
        if ($product->product_type === 'ppf') {
            return 'full_set';
        }

        return match ($product->position) {
            'front' => 'front',
            'side_rear' => 'side',
            default => 'full_set',
        };
    }

    /**
     * @return array{base_price: float, coefficient: float|null, car_part: string, price: float|null, filled: bool}
     */
    public static function calculate(FilmProduct $product, string $vehicleSize): array
    {
        $carPart = self::carPartFor($product);
        $basePrice = (float) $product->base_price;

        $rule = PriceRule::query()
            ->where('vehicle_size', $vehicleSize)
            ->where('car_part', $carPart)
            ->first();

        $coefficient = $rule ? (float) $rule->coefficient : null;
        $filled = $basePrice > 0 && $coefficient !== null;

        return [
            'base_price' => $basePrice,
            'coefficient' => $coefficient,
            'car_part' => $carPart,
            'price' => $filled ? round($basePrice * $coefficient, 2) : null,
            'filled' => $filled,
        ];
    }

    /**
     * Simulasi harga untuk kelima ukuran kendaraan.
     *
     * @return array<string, array{base_price: float, coefficient: float|null, car_part: string, price: float|null, filled: bool}>
     */
    public static function matrix(FilmProduct $product): array
    {
        $out = [];

        foreach (self::VEHICLE_SIZES as $size) {
            $out[$size] = self::calculate($product, $size);
        }

        return $out;
    }
}
