<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\FilmProduct;
use App\Models\CustomerVehicle;
use App\Services\PriceCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/booking/services — katalog layanan yang bisa dipesan beserta estimasi harga dan durasi (publik), dipakai langkah
 * "Layanan" di booking v2 (Fase 1 pengerjaan ulang booking, 2026-10-15). Opsional ?vehicle_size=S|M|L|XL|XXL untuk harga
 * PPF/kaca film sesuai ukuran mobil.
 *
 * Aturan harga (keputusan pemilik 2026-10-14): Detailing dan Premium Wash = SATU harga tetap (FilmProduct.base_price) untuk
 * semua ukuran; PPF dan Kaca Film = "mulai dari" dari matriks harga per ukuran. Harga final tetap dikonfirmasi toko.
 */
class BookingCatalogController extends Controller
{
    /** Urutan tampil dan urutan penyusunan service_type; `flag` = kolom booking yang dinyalakan. */
    public const SERVICES = [
        'kaca_film' => [
            'label' => 'Kaca Film (Window Film)', 'flag' => 'product_kaca_film', 'types' => ['window_film'],
            'capacity_category' => 'berat', 'fixed_price' => false,
            'description' => 'Kaca film untuk kenyamanan, privasi, dan penolak panas.',
        ],
        'ppf' => [
            'label' => 'Pelindung Cat (PPF)', 'flag' => 'product_ppf', 'types' => ['ppf'],
            'capacity_category' => 'berat', 'fixed_price' => false,
            'description' => 'Lapisan pelindung cat dari baret, goresan halus, dan batu kerikil.',
        ],
        'detailing' => [
            'label' => 'Detailing', 'flag' => 'product_detailing', 'types' => ['detailing'],
            'capacity_category' => 'ringan', 'fixed_price' => true,
            'description' => 'Perawatan menyeluruh interior dan eksterior.',
        ],
        'premium_wash' => [
            'label' => 'Premium Wash', 'flag' => 'product_premium_wash', 'types' => ['premium_wash'],
            'capacity_category' => 'ringan', 'fixed_price' => true,
            'description' => 'Cuci premium dengan perawatan detail.',
        ],
    ];

    public function services(Request $request): JsonResponse
    {
        $size = $request->query('vehicle_size');
        $size = in_array($size, CustomerVehicle::SIZES, true) ? $size : null;

        $services = [];

        foreach (self::SERVICES as $key => $service) {
            $products = FilmProduct::with('prices')->where('is_active', true)->whereIn('product_type', $service['types'])->get();

            // Detailing/Premium Wash hanya tampil kalau produknya aktif; PPF/Kaca Film selalu tampil (harga bisa belum terisi).
            if ($service['fixed_price'] && $products->isEmpty()) {
                continue;
            }

            $services[] = [
                'key' => $key,
                'label' => $service['label'],
                'description' => $service['description'],
                'capacity_category' => $service['capacity_category'],
                'estimated_duration_days' => $key === 'ppf' ? Booking::DEFAULT_DURATION_DAYS_PPF : Booking::DEFAULT_DURATION_DAYS_DEFAULT,
                'price' => $this->price($products, $service['fixed_price'], $size),
            ];
        }

        return response()->json(['success' => true, 'data' => ['vehicle_size' => $size, 'services' => $services]]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FilmProduct>  $products
     * @return array{type: string, amount: float|null, label: string}
     */
    private function price($products, bool $fixed, ?string $size): array
    {
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
