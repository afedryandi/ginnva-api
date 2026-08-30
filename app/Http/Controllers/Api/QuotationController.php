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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use App\Mail\QuotationReceivedMail;

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
        // Semua merek yang sudah terdaftar (termasuk yang belum punya model)
        // untuk level pertama cascading dropdown.
        $brands = Vehicle::select('brand')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand');

        // Hanya kendaraan yang sudah punya tipe/model untuk dipilih di form.
        // Entry merek-only (model=null) hanya tampil di panel admin sebagai
        // placeholder sampai admin melengkapi tipe spesifiknya.
        $vehicles = Vehicle::select('id', 'brand', 'model', 'variant')
            ->whereNotNull('model')
            ->orderBy('brand')
            ->orderBy('model')
            ->orderBy('variant')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'brands'   => $brands,
                'vehicles' => $vehicles,
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
        try {
            $request->validate([
                'vehicle_id'      => 'required|exists:vehicles,id',
                // SEBELUMNYA 'required' -- form quote di website
                // (ginnva-web/app/quote/QuoteForm.tsx) TIDAK PERNAH
                // mengirim store_id sama sekali (GET /api/quotation/options
                // juga tidak menyediakan daftar toko untuk dipilih), jadi
                // SETIAP submit dari web pasti gagal 422. Sistem sendiri
                // sudah dirancang menerima lead tanpa toko -- lihat
                // Staff\QuotationController::index() yang eksplisit
                // menangani 'orWhereNull(store_id)' untuk "lead lama yang
                // store_id-nya masih null". Staff assign toko belakangan
                // saat follow-up. Ditemukan & diperbaiki 2026-08-29.
                'store_id'        => 'nullable|exists:stores,id',
                'customer_name'   => 'required|string|max:255',
                'customer_email'  => 'required|email|max:255',
                'customer_phone'  => 'required|string|max:30',
                'license_plate'   => 'nullable|string|max:20',
                'message'         => 'nullable|string|max:1000',
                'items'           => 'required|array|min:1',
                'items.*.film_product_id' => 'required|exists:film_products,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $e->errors(),
            ], 422);
        }

        // customer_email TIDAK diverifikasi kepemilikannya (form ini
        // sengaja tanpa OTP supaya funnel lead tetap 1-langkah) — siapa
        // pun bisa mengetik email orang lain, dan sistem otomatis kirim
        // email atas nama Ginnva ke situ (QuotationReceivedMail).
        // Throttle per-IP (route, 5/menit) tidak menutup celah ini kalau
        // penyerang ganti-ganti IP dalam waktu lebih lama — batas
        // TAMBAHAN per alamat email ini yang benar-benar membatasi berapa
        // kali 1 alamat bisa "dibombardir", terlepas dari IP pengirimnya.
        // Verifikasi kepemilikan email penuh (OTP) sengaja TIDAK
        // dikerjakan di sini — itu perubahan UX besar ke funnel lead yang
        // sengaja dibuat serendah mungkin gesekannya, keputusan terpisah.
        $recentToSameEmail = Quotation::where('customer_email', $request->customer_email)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        if ($recentToSameEmail >= 3) {
            return response()->json([
                'success' => false,
                'message' => 'Sudah ada beberapa permintaan penawaran ke email ini dalam 24 jam terakhir. Hubungi toko langsung kalau perlu bantuan lebih cepat.',
            ], 429);
        }

        $quotation = DB::transaction(function () use ($request) {
            $quotationNumber = $this->generateQuotationNumber();

            $quotation = Quotation::create([
                'quotation_number' => $quotationNumber,
                'vehicle_id'       => $request->vehicle_id,
                'store_id'         => $request->store_id,
                'customer_name'    => $request->customer_name,
                'customer_phone'   => $request->customer_phone,
                'customer_email'   => $request->customer_email,
                'license_plate'    => $request->license_plate,
                'status'           => 'new',
                'source'           => 'customer',
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

        try {
            Mail::to($request->customer_email)
                ->send(new QuotationReceivedMail($quotation->load('vehicle', 'items.filmProduct')));
        } catch (\Exception $e) {
            Log::warning('[QuotationMail] Gagal kirim: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Permintaan penawaran berhasil dikirim. Tim sales kami akan segera menghubungi Anda.',
            'data' => [
                'quotation_number' => $quotation->quotation_number,
            ],
        ], 201);
    }

    /**
     * Sebelumnya nomor di-generate sekali tanpa cek unik sama sekali,
     * padahal quotation_number UNIQUE di database — kalau ada tabrakan
     * (2 submission di bulan yang sama dapat 4 karakter acak yang sama),
     * customer dapat 500 mentah. Prefix "INQ-" (beda dari "QTN-" yang
     * dipakai QuotationResource::generateQuotationNumber() untuk quotation
     * yang dibuat manual staff) sengaja dipertahankan supaya asal lead
     * customer vs entri manual tetap bisa dibedakan dari nomornya.
     */
    private function generateQuotationNumber(): string
    {
        do {
            $candidate = 'INQ-' . date('Ym') . '-' . strtoupper(Str::random(4));
        } while (Quotation::where('quotation_number', $candidate)->exists());

        return $candidate;
    }
}