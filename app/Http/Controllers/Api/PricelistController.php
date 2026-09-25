<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PricelistDataService;
use App\Services\PricelistTokenService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * API untuk kalkulator "Price List Kaca Film" (dipakai tim sales internal
 * & dealer lewat halaman kalkulator terpisah di ginnva-web). TIDAK
 * berhubungan dengan sistem auth staff Ginnva (User/Sanctum) — login
 * pakai 1 akun BERSAMA (username+password di .env, lihat PRICELIST_USERNAME/
 * PRICELIST_PASSWORD), bukan identitas per-orang. Kalau ada staff resign,
 * admin ganti password ini di .env supaya akses lama otomatis tidak
 * berlaku lagi (sesi lama tetap jalan sampai token-nya kedaluwarsa 8 jam,
 * TIDAK langsung dicabut -- lihat PricelistTokenService, stateless jadi
 * tidak ada mekanisme revoke paksa per-token). Data harga/ukuran tetap
 * dibaca live dari Google Sheet yang dikelola owner.
 */
class PricelistController extends Controller
{
    public function __construct(
        private PricelistTokenService $tokens,
        private PricelistDataService $data,
    ) {
    }

    /**
     * POST /api/pricelist/login
     * Body: { username: string, password: string }
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $validUsername = (string) config('services.google_pricelist.username');
        $validPassword = (string) config('services.google_pricelist.password');

        // hash_equals() dua kali (username & password terpisah) supaya
        // waktu perbandingan tidak bocorin informasi lewat timing attack
        // -- meski threat model fitur ini rendah (1 akun bersama internal/
        // dealer), tetap murah utk dilakukan benar sejak awal.
        $usernameMatches = $validUsername !== '' && hash_equals($validUsername, (string) $request->input('username'));
        $passwordMatches = $validPassword !== '' && hash_equals($validPassword, (string) $request->input('password'));

        if (! $usernameMatches || ! $passwordMatches) {
            return response()->json([
                'success' => false,
                'message' => 'Username atau password salah.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'token' => $this->tokens->issue(),
        ]);
    }

    /**
     * GET /api/pricelist/brands
     */
    public function brands()
    {
        return $this->withSheetsErrorHandling(fn () => response()->json([
            'success' => true,
            'data' => $this->data->getBrands(),
        ]));
    }

    /**
     * GET /api/pricelist/models?brand=...
     */
    public function models(Request $request)
    {
        $request->validate([
            'brand' => 'required|string',
        ]);

        return $this->withSheetsErrorHandling(fn () => response()->json([
            'success' => true,
            'data' => $this->data->getModels($request->query('brand')),
        ]));
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

        return $this->withSheetsErrorHandling(function () use ($request) {
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
        });
    }

    /**
     * GET /api/pricelist/prices
     */
    public function prices()
    {
        return $this->withSheetsErrorHandling(fn () => response()->json([
            'success' => true,
            'data' => $this->data->getHargaMap(),
        ]));
    }

    /**
     * GoogleSheetsService/PricelistDataService melempar RuntimeException
     * mentah (pesan HTTP Google Sheets API, mis. "HTTP 403") kalau gagal
     * baca sheet -- itu detail teknis yang TIDAK boleh ditampilkan ke
     * sales (bukan salah mereka, dan tidak membantu mereka), tapi WAJIB
     * dicatat ke log supaya admin bisa diagnosa (mis. Sheet belum
     * di-share ke service account, atau Sheets API belum aktif).
     */
    private function withSheetsErrorHandling(\Closure $callback)
    {
        try {
            return $callback();
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Server sedang bermasalah mengambil data. Coba beberapa saat lagi, atau hubungi admin Ginnva.',
            ], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan tak terduga. Coba beberapa saat lagi, atau hubungi admin Ginnva.',
            ], 500);
        }
    }
}
