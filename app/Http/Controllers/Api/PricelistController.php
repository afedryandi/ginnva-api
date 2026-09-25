<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleIdTokenVerifier;
use App\Services\PricelistDataService;
use App\Services\PricelistTokenService;
use Illuminate\Http\Request;

/**
 * API untuk kalkulator "Price List Kaca Film" (dipakai tim sales lewat
 * halaman kalkulator terpisah di ginnva-web). TIDAK berhubungan dengan
 * sistem auth staff Ginnva (User/Sanctum) — login lewat Google ID token,
 * allow-list & data harga/ukuran semuanya dibaca live dari Google Sheet
 * yang dikelola owner, bukan dari database.
 */
class PricelistController extends Controller
{
    public function __construct(
        private GoogleIdTokenVerifier $googleVerifier,
        private PricelistTokenService $tokens,
        private PricelistDataService $data,
    ) {
    }

    /**
     * POST /api/pricelist/login
     * Body: { credential: <Google ID token dari Sign in with Google> }
     */
    public function login(Request $request)
    {
        $request->validate([
            'credential' => 'required|string',
        ]);

        $email = $this->googleVerifier->verify($request->input('credential'));

        if ($email === null) {
            return response()->json([
                'success' => false,
                'message' => 'Login Google tidak valid atau sudah kedaluwarsa. Silakan coba lagi.',
            ], 422);
        }

        if (! $this->data->isEmailAllowed($email)) {
            return response()->json([
                'success' => false,
                'message' => 'Email ini belum terdaftar untuk akses kalkulator ini. Hubungi admin Ginnva.',
            ], 403);
        }

        $token = $this->tokens->issue($email);

        return response()->json([
            'success' => true,
            'token' => $token,
            'email' => $email,
        ]);
    }

    /**
     * GET /api/pricelist/brands
     */
    public function brands()
    {
        return response()->json([
            'success' => true,
            'data' => $this->data->getBrands(),
        ]);
    }

    /**
     * GET /api/pricelist/models?brand=...
     */
    public function models(Request $request)
    {
        $request->validate([
            'brand' => 'required|string',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->data->getModels($request->query('brand')),
        ]);
    }

    /**
     * GET /api/pricelist/car?brand=...&tipe=...
     */
    public function car(Request $request)
    {
        $request->validate([
            'brand' => 'required|string',
            'tipe' => 'required|string',
        ]);

        $sqm = $this->data->getCarSqm($request->query('brand'), $request->query('tipe'));

        if ($sqm === null) {
            return response()->json([
                'success' => false,
                'message' => 'Data ukuran untuk mobil ini tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $sqm,
        ]);
    }

    /**
     * GET /api/pricelist/prices
     */
    public function prices()
    {
        return response()->json([
            'success' => true,
            'data' => $this->data->getHargaMap(),
        ]);
    }
}
