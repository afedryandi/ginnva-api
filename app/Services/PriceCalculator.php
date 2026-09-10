<?php

namespace App\Services;

use App\Models\FilmProduct;

/**
 * Harga jual Produk Film — INTERNAL Ginnva saja (tidak ditampilkan ke
 * publik/customer, per keputusan user 2026-09-10).
 *
 * Model harga: MATRIKS. Ekspor daftar harga Majoo (2026-09-10)
 * membuktikan `base_price × koefisien tunggal` tidak bisa mereproduksi
 * harga riil — rasio antar-ukuran beda tiap lini. Jadi:
 *   - `FilmProduct.base_price` = harga flat / dasar (produk yg harganya
 *     sama semua ukuran, mis. Panoramic)
 *   - `film_product_prices` (FilmProduct::prices) = harga spesifik per
 *     ukuran kendaraan (Platinum / Signature / PPF)
 *
 * priceFor(): cari baris ukuran → fallback ke base_price → null kalau
 * dua-duanya kosong (UI tampilkan "Belum diisi", bukan Rp 0 palsu).
 *
 * Tabel `price_rules` / menu "Koefisien Harga" DITINGGALKAN (di-hide
 * lagi) — digantikan matriks ini.
 */
class PriceCalculator
{
    public const VEHICLE_SIZES = ['S', 'M', 'L', 'XL', 'XXL'];

    public static function priceFor(FilmProduct $product, ?string $vehicleSize): ?float
    {
        if ($vehicleSize !== null) {
            $row = $product->prices->firstWhere('vehicle_size', $vehicleSize);

            if ($row !== null) {
                return (float) $row->price;
            }
        }

        $base = (float) $product->base_price;

        return $base > 0 ? $base : null;
    }

    /**
     * Harga untuk kelima ukuran + harga flat.
     *
     * @return array{S: float|null, M: float|null, L: float|null, XL: float|null, XXL: float|null, flat: float|null}
     */
    public static function matrix(FilmProduct $product): array
    {
        $out = [];

        foreach (self::VEHICLE_SIZES as $size) {
            $row = $product->prices->firstWhere('vehicle_size', $size);
            $out[$size] = $row !== null ? (float) $row->price : null;
        }

        $base = (float) $product->base_price;
        $out['flat'] = $base > 0 ? $base : null;

        return $out;
    }

    /**
     * Apakah produk ini sudah punya harga (flat atau per ukuran)?
     */
    public static function isPriced(FilmProduct $product): bool
    {
        return (float) $product->base_price > 0 || $product->prices->isNotEmpty();
    }
}
