<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vehicle;
use App\Models\FilmProduct;
use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * QuotationController — versi lead capture.
 *
 * Ginnva Shield Indonesia baru expand dari China, harga jual produk
 * di Indonesia belum ditentukan. Jadi quotation di sini TIDAK menghitung
 * harga apapun — fungsinya hanya menangkap minat/kontak customer yang
 * benar-benar ingin beli, supaya sales bisa follow up dan bicarakan
 * harga secara langsung.
 */
class QuotationController extends Controller
{
    /**
     * GET /api/quotation/options
     * Data untuk dropdown di form quotation (vehicle & produk).
     * Dipisah dari submit supaya frontend bisa load pilihan form
     * tanpa perlu submit data dulu.
     */
    public function options()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'vehicles' => Vehicle::select('id', 'brand', 'model')->orderBy('brand')->get(),
                'products' => FilmProduct::where('is_active', true)
                    ->select('id', 'name', 'product_type')
                    ->orderBy('product_type')
                    ->get(),
            ],
        ]);
    }

    /**
     * POST /api/quotation/submit
     * Menyimpan minat/inquiry customer. Tidak ada perhitungan harga sama sekali.
     */
    public function submit(Request $request)
    {
        $request->validate([
            'vehicle_id'      => 'required|exists:vehicles,id',
            'customer_name'   => 'required|string|max:255',
            'customer_phone'  => 'required|string|max:30',
            'license_plate'   => 'nullable|string|max:20',
            'message'         => 'nullable|string|max:1000',
            'items'           => 'required|array|min:1',
            'items.*.film_product_id' => 'required|exists:film_products,id',
        ]);

        $quotation = DB::transaction(function () use ($request) {
            $quotationNumber = 'INQ-' . date('Ym') . '-' . strtoupper(Str::random(4));

            $quotation = Quotation::create([
                'quotation_number' => $quotationNumber,
                'vehicle_id'       => $request->vehicle_id,
                'customer_name'    => $request->customer_name,
                'customer_phone'   => $request->customer_phone,
                'license_plate'    => $request->license_plate,
                'status'           => 'new',
                'message'          => $request->message,
            ]);

            foreach ($request->items as $item) {
                QuotationItem::create([
                    'quotation_id'    => $quotation->id,
                    'film_product_id' => $item['film_product_id'],
                ]);
            }

            return $quotation;
        });

        // TODO: kirim notifikasi ke sales (email/WhatsApp/Slack) begitu inquiry baru masuk,
        // supaya follow up bisa secepat mungkin tanpa sales harus cek dashboard manual.

        return response()->json([
            'success' => true,
            'message' => 'Permintaan penawaran berhasil dikirim. Tim sales kami akan segera menghubungi Anda.',
            'data' => [
                'quotation_number' => $quotation->quotation_number,
            ],
        ], 201);
    }
}