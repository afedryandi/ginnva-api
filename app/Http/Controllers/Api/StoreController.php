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
            'data'    => $stores->map(fn (Store $store) => static::publicFields($store)),
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
            'data'    => static::publicFields($store),
        ]);
    }

    /**
     * SEBELUMNYA index()/show() return Store model MENTAH langsung —
     * otomatis ikut expose field internal HR/payroll yang tidak ada
     * hubungannya dengan halaman publik "Lokasi Dealer": attendance_
     * radius_meters (radius toleransi GPS absen — kalau bocor, staff
     * nakal bisa tahu persis batas curang), late_tolerance_minutes &
     * late_deduction_amount (kebijakan potongan gaji internal),
     * install_capacity_per_day (kapasitas operasional internal).
     * Dikonfirmasi mobile app (stores.tsx) & web (DealersList.tsx) tidak
     * pernah pakai field-field itu sama sekali — murni ke-leak tidak
     * sengaja. Whitelist manual di sini (bukan bikin Laravel API
     * Resource baru — project ini konsisten tidak pakai pola itu di
     * controller lain) supaya field baru yang ditambah ke Store nanti
     * tidak otomatis ikut bocor lagi tanpa sadar. Ditemukan & diperbaiki
     * 2026-08-29, audit modul Toko/Dealer.
     */
    private static function publicFields(Store $store): array
    {
        return [
            'id'                    => $store->id,
            'name'                  => $store->name,
            'city'                  => $store->city,
            'address'               => $store->address,
            'phone'                 => $store->phone,
            'latitude'              => $store->latitude,
            'longitude'             => $store->longitude,
            'maps_url'              => $store->maps_url,
            // Array mentah (BUKAN cuma opening_hours_lines yang sudah
            // diformat jadi teks) -- dipakai booking/index.tsx di mobile
            // app untuk MENGHITUNG tanggal kerja/libur toko sendiri di
            // date picker (bandingkan Date.getDay() vs Store::DAYS), jadi
            // wajib bentuk array asli, bukan teks ringkasan. Jadwal
            // buka-tutup toko sendiri bukan data sensitif (beda dari
            // radius absen/potongan gaji), aman untuk publik. Ditemukan
            // saat audit modul Toko/Dealer 2026-08-29 -- field ini
            // sempat kehapus di whitelist pertama, hampir bikin regresi
            // date picker booking.
            'opening_hours'         => $store->opening_hours,
            'opening_hours_lines'   => $store->opening_hours_lines,
            'opening_hours_schema'  => $store->opening_hours_schema,
            'reviews_count'         => $store->reviews_count,
            'positive_rate_percent' => $store->positive_rate_percent,
        ];
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