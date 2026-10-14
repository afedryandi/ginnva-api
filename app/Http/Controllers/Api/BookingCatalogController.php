<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingServiceType;
use App\Models\CustomerVehicle;
use App\Models\FilmProduct;
use App\Services\PriceCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/booking/services — katalog layanan yang bisa dipesan (publik), dipakai langkah "Layanan" di booking v2 (Fase 1
 * pengerjaan ulang booking, 2026-10-15). Nama, deskripsi, kategori kapasitas, lama pengerjaan, urutan, dan aktif dibaca dari
 * tabel booking_service_types (diubah admin lewat Filament: Booking → Layanan Booking, Fase 2).
 *
 * Harga TIDAK dikirim secara bawaan (keputusan pemilik 2026-10-15: harga dikonfirmasi toko). Kalau config('booking.show_prices')
 * dinyalakan: Detailing dan Premium Wash = SATU harga tetap (FilmProduct.base_price) untuk semua ukuran; PPF dan Kaca Film =
 * "mulai dari" dari matriks harga per ukuran. Opsional ?vehicle_size=S|M|L|XL|XXL untuk harga sesuai ukuran mobil.
 */
class BookingCatalogController extends Controller
{
    /** Jenis produk (FilmProduct.product_type) tiap layanan, dan apakah harganya tetap — hanya dipakai bila harga dinyalakan. */
    public const PRODUCT_TYPES = [
        'kaca_film' => ['types' => ['window_film'], 'fixed_price' => false],
        'ppf' => ['types' => ['ppf'], 'fixed_price' => false],
        'detailing' => ['types' => ['detailing'], 'fixed_price' => true],
        'premium_wash' => ['types' => ['premium_wash'], 'fixed_price' => true],
    ];

    public function services(Request $request): JsonResponse
    {
        $size = $request->query('vehicle_size');
        $size = in_array($size, CustomerVehicle::SIZES, true) ? $size : null;
        $showPrices = (bool) config('booking.show_prices');

        $services = [];

        foreach (BookingServiceType::activeCatalog() as $type) {
            if (! isset(BookingServiceType::FLAGS[$type->key])) {
                continue; // baris tak dikenal (kunci di luar empat yang didukung)
            }

            $services[] = [
                'key' => $type->key,
                'label' => $type->label,
                'description' => $type->description,
                'capacity_category' => $type->capacity_category,
                'estimated_duration_days' => (int) $type->estimated_duration_days,
                'price' => $showPrices ? $this->price($type->key, $size) : null,
            ];
        }

        return response()->json(['success' => true, 'data' => ['vehicle_size' => $size, 'services' => $services]]);
    }

    /**
     * @return array{type: string, amount: float|null, label: string}
     */
    private function price(string $key, ?string $size): array
    {
        $config = self::PRODUCT_TYPES[$key] ?? ['types' => [], 'fixed_price' => false];
        $fixed = $config['fixed_price'];
        $products = FilmProduct::with('prices')->where('is_active', true)->whereIn('product_type', $config['types'])->get();

        $amounts = $products->map(function (FilmProduct $product) use ($fixed, $size) {
            if ($fixed) {
                return (float) $product->base_price > 0 ? (float) $product->base_price : null;
            }

            if ($size !== null) {
                return PriceCalculator::priceFor($product, $size);
            }

            $matrix = PriceCalculator::matrix($product);

            return collect($matrix)->filter(fn ($p) => $p !== null)->min();
        })->filter(fn ($amount) => $amount !== null && $amount > 0);

        if ($amounts->isEmpty()) {
            return ['type' => 'unavailable', 'amount' => null, 'label' => 'Harga dikonfirmasi toko'];
        }

        $amount = (float) ($fixed ? $amounts->first() : $amounts->min());
        $formatted = 'Rp' . number_format($amount, 0, ',', '.');

        return $fixed
            ? ['type' => 'fixed', 'amount' => $amount, 'label' => $formatted]
            : ['type' => 'from', 'amount' => $amount, 'label' => 'Mulai dari ' . $formatted];
    }
}
