<?php

namespace App\Services;

use App\Models\Booking;

/**
 * Membagi nominal sebuah booking ke produk (SKU) untuk laporan Penjualan Produk dan grafiknya.
 *
 * Booking dengan rincian harga (booking_sale_lines, Penjualan Fase 1): nominal bersih (setelah voucher/promo/nego) dibagi
 * proporsional menurut nilai tiap baris, sisa pembulatan ke bagian terakhir -- sama dengan BookingRevenueSplitter, hanya per SKU.
 * Baris tanpa SKU dikelompokkan per jenis layanan ("Belum Diisi SKU -- PPF").
 *
 * Booking lama tanpa rincian bernilai: seluruh nominal ke SKU utama booking (film_product_id), atau ke "Belum Diisi SKU" bila kosong.
 * Produk tambahan lama (booking_film_products) tidak punya harga, jadi tidak ikut membagi.
 */
class ProductSalesAllocator
{
    /**
     * @return array<string, array{product_id: ?int, service: string, amount: float, legacy: bool}>
     *         kunci 'p{id}' untuk SKU, atau 'none:{jenis layanan}' untuk yang belum diisi
     */
    public static function allocate(Booking $booking, float $amount): array
    {
        $lines = $booking->relationLoaded('saleLines') ? $booking->saleLines : $booking->saleLines()->get();

        $weights = [];
        foreach ($lines as $line) {
            if (! isset(BookingRevenueSplitter::PRODUCTS[$line->service_type])) {
                continue;
            }

            $key = $line->film_product_id ? 'p' . $line->film_product_id : 'none:' . $line->service_type;
            $weights[$key] ??= ['product_id' => $line->film_product_id ? (int) $line->film_product_id : null, 'service' => $line->service_type, 'weight' => 0.0];
            $weights[$key]['weight'] += max(0.0, (float) $line->line_total);
        }

        $total = array_sum(array_column($weights, 'weight'));

        if ($weights === [] || $total <= 0.0) {
            $service = implode('+', BookingRevenueSplitter::productKeys($booking));
            $key = $booking->film_product_id ? 'p' . $booking->film_product_id : 'none:' . $service;

            return [$key => [
                'product_id' => $booking->film_product_id ? (int) $booking->film_product_id : null,
                'service' => $service,
                'amount' => $amount,
                'legacy' => true,
            ]];
        }

        $weights = array_filter($weights, fn (array $w) => $w['weight'] > 0.0);
        $last = array_key_last($weights);
        $allocated = 0.0;
        $result = [];

        foreach ($weights as $key => $w) {
            $portion = $key === $last ? round($amount - $allocated, 2) : round($amount * $w['weight'] / $total, 2);
            $allocated += $portion;
            $result[$key] = ['product_id' => $w['product_id'], 'service' => $w['service'], 'amount' => $portion, 'legacy' => false];
        }

        return $result;
    }
}
