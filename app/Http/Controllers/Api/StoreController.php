<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedDate;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    /**
     * GET /api/stores
     *
     * Daftar toko/dealer aktif, untuk halaman "Lokasi Dealer".
     * Read-only — data diisi/diubah manual lewat database atau Filament,
     * tidak ada endpoint create/update/delete yang terbuka ke publik.
     *
     * Query param opsional:
     *   ?city=Jakarta   — filter berdasarkan kota (partial match, case-insensitive)
     */
    public function index(Request $request): JsonResponse
    {
        $stores = Store::query()
            ->where('is_active', true)
            ->when($request->query('city'), function ($query, $city) {
                $query->where('city', 'like', '%' . $city . '%');
            })
            ->orderBy('city')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $stores,
        ]);
    }

    /**
     * GET /api/stores/{id}
     *
     * Detail satu toko. Mengembalikan 404 jika tidak ditemukan atau
     * sedang tidak aktif (disembunyikan dari publik).
     */
    public function show(int $id): JsonResponse
    {
        $store = Store::query()
            ->where('is_active', true)
            ->find($id);

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Data toko tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $store,
        ]);
    }

    /**
     * GET /api/stores/{id}/blocked-dates
     *
     * Daftar tanggal yang diblokir untuk toko ini (30 hari ke depan).
     * Dipakai mobile app untuk disable tanggal yang tidak tersedia
     * di picker booking.
     */
    public function blockedDates(int $id): JsonResponse
    {
        $dates = BlockedDate::where('store_id', $id)
            ->whereDate('date', '>=', today())
            ->whereDate('date', '<=', today()->addDays(30))
            ->pluck('date')
            ->map(fn ($date) => $date->format('Y-m-d'));

        return response()->json([
            'success' => true,
            'data'    => $dates,
        ]);
    }
}