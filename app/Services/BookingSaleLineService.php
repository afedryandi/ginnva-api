<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSaleLine;
use App\Models\FilmProduct;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rincian harga per layanan pada booking (Penjualan Fase 1, 2026-11-10). Satu-satunya jalur menyimpan baris: dipanggil dari aksi
 * Proses Referral (dan approve() permintaan yang sama) di dalam transaksi yang sama dengan penyimpanan nominal, SEBELUM jurnal pendapatan
 * dibuat ulang, supaya pembagian pendapatan memakai baris terbaru.
 *
 * Aturan wajib: bila config('booking.sale_lines_required_from') berisi tanggal, booking yang DIBUAT pada atau setelah tanggal itu
 * wajib punya minimal satu baris bernilai saat nominal diproses. Booking lama tidak pernah dipaksa.
 */
class BookingSaleLineService
{
    /** Booking ini wajib punya rincian harga saat nominal diproses? */
    public static function isRequiredFor(Booking $booking): bool
    {
        $from = config('booking.sale_lines_required_from');

        if (! $from || ! $booking->created_at) {
            return false;
        }

        return $booking->created_at->toDateString() >= $from;
    }

    /**
     * Bersihkan dan periksa baris dari form/payload. Baris kosong total diabaikan.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException pesan siap tampil bila ada baris tidak valid
     */
    public function normalize(array $rows): array
    {
        $clean = [];

        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            $type = $row['service_type'] ?? null;
            $price = $row['unit_price'] ?? null;

            if (($type === null || $type === '') && ($price === null || $price === '')) {
                continue;
            }

            if (! isset(BookingSaleLine::SERVICE_TYPES[$type])) {
                throw new RuntimeException("Baris {$n}: jenis layanan belum dipilih.");
            }

            if (! is_numeric($price) || (float) $price < 0) {
                throw new RuntimeException("Baris {$n}: harga satuan harus berupa angka nol atau lebih.");
            }

            $quantity = $row['quantity'] ?? 1;
            if (! is_numeric($quantity) || (float) $quantity <= 0) {
                throw new RuntimeException("Baris {$n}: jumlah harus lebih dari nol.");
            }

            $discount = $row['discount_amount'] ?? 0;
            $discount = ($discount === '' || $discount === null) ? 0 : $discount;
            if (! is_numeric($discount) || (float) $discount < 0) {
                throw new RuntimeException("Baris {$n}: potongan harus berupa angka nol atau lebih.");
            }

            if ((float) $discount > (float) $quantity * (float) $price + 0.009) {
                throw new RuntimeException("Baris {$n}: potongan tidak boleh melebihi harga baris.");
            }

            $clean[] = [
                'service_type' => $type,
                'film_product_id' => ! empty($row['film_product_id']) ? (int) $row['film_product_id'] : null,
                'position' => isset($row['position']) && trim((string) $row['position']) !== '' ? mb_substr(trim((string) $row['position']), 0, 80) : null,
                'description' => isset($row['description']) && trim((string) $row['description']) !== '' ? mb_substr(trim((string) $row['description']), 0, 160) : null,
                'quantity' => round((float) $quantity, 2),
                'unit_price' => round((float) $price, 2),
                'discount_amount' => round((float) $discount, 2),
            ];
        }

        return $clean;
    }

    /** Jumlah nilai baris (sebelum potongan tingkat transaksi) dari baris yang sudah dinormalkan. */
    public static function total(array $rows): float
    {
        return round((float) array_sum(array_map(
            fn (array $r) => (float) $r['quantity'] * (float) $r['unit_price'] - (float) ($r['discount_amount'] ?? 0),
            $rows
        )), 2);
    }

    /**
     * Ganti seluruh baris booking dengan $rows (sudah dinormalkan). Daftar kosong menghapus semua baris.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function replace(Booking $booking, array $rows): void
    {
        DB::transaction(function () use ($booking, $rows) {
            BookingSaleLine::where('booking_id', $booking->id)->delete();

            foreach ($rows as $index => $row) {
                $product = $row['film_product_id'] ? FilmProduct::withTrashed()->with(['prices', 'groupPrices'])->find($row['film_product_id']) : null;
                $reference = $product ? PriceCalculator::priceFor($product, $booking->vehicle_size) : null;

                BookingSaleLine::create($row + [
                    'booking_id' => $booking->id,
                    'reference_price' => $reference,
                    'sort_order' => $index,
                ]);
            }

            $booking->unsetRelation('saleLines');
        });
    }
}
