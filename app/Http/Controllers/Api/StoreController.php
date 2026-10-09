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

    /**
     * GET /api/stores/{id}/unavailable-dates -- tanggal (30 hari ke depan)
     * yang TIDAK bisa dipilih untuk jadwal ulang: toko tutup (libur mingguan
     * atau diblokir admin) ATAU kapasitas penuh. Dipakai modal jadwal ulang
     * customer & staff (2026-10-03).
     */
    public function unavailableDates(Request $request, int $id): JsonResponse
    {
        $store = \App\Models\Store::whereKey($id)->where('is_active', true)->first();
        abort_if(! $store, 404);

        // ?booking_id= (opsional): booking yang SEDANG dipindah -- dikecualikan
        // dari hitungan (tanggalnya sendiri tidak dianggap penuh) dan dicek
        // sepanjang DURASInya, sama dengan aturan server saat menyimpan.
        $excludeId = null;
        $duration = 1;
        if ($request->filled('booking_id')) {
            // Hanya pengguna login yang boleh memakai booking_id (endpoint publik
            // sebelumnya bisa dipakai menebak id booking & membanjiri cache):
            // customer hanya untuk booking miliknya, staff untuk booking toko.
            $customer = $request->user('customer');
            $staff = $customer ? null : $request->user('api');
            $b = ($customer || $staff)
                ? \App\Models\Booking::where('id', (int) $request->booking_id)->where('store_id', $id)
                    ->when($customer, fn ($q) => $q->where('customer_id', $customer->id))
                    ->first()
                : null;
            if ($b) {
                $excludeId = $b->id;
                $duration = max(1, (int) $b->duration_days);
            }
        }

        $dates = \Illuminate\Support\Facades\Cache::remember("store-unavailable-dates:{$id}:{$excludeId}:{$duration}", 60, function () use ($store, $id, $excludeId, $duration) {
            $counts = \App\Models\Booking::confirmedOverlapCountsForRange($id, today(), today()->addDays(60), $excludeId);
            $capacity = [];
            $isFull = function (string $key) use (&$capacity, $counts, $id): bool {
                $capacity[$key] ??= \App\Models\Booking::capacityForDate($id, \Illuminate\Support\Carbon::parse($key));

                return ($counts[$key] ?? 0) >= $capacity[$key];
            };
            $out = [];

            for ($i = 0; $i <= 30; $i++) {
                $day = today()->addDays($i);
                $blocked = $store->isClosedOn($day);

                if (! $blocked) {
                    foreach (\App\Models\Booking::workingDatesInRange($id, $day, $duration, $store) as $workDay) {
                        if ($isFull($workDay)) {
                            $blocked = true;
                            break;
                        }
                    }
                }

                if ($blocked) {
                    $out[] = $day->toDateString();
                }
            }

            return $out;
        });

        return response()->json(['success' => true, 'data' => $dates]);
    }

    /**
     * GET /api/stores/{id}/full-dates -- tanggal (30 hari ke depan) yang
     * kapasitas instalasi tokonya SUDAH PENUH (hanya booking 'confirmed'
     * yang dihitung, sama seperti saat staff mengonfirmasi). Dipakai pemilih
     * tanggal booking customer untuk menonaktifkan tanggal penuh (keputusan
     * 2026-10-02). Di-cache 60 detik karena endpoint publik.
     */
    public function fullDates(int $id): JsonResponse
    {
        // Hanya toko aktif -- mencegah enumerasi id mengisi cache & membebani DB.
        if (! \App\Models\Store::whereKey($id)->where('is_active', true)->exists()) {
            abort(404);
        }

        $dates = \Illuminate\Support\Facades\Cache::remember("store-full-dates:{$id}", 60, function () use ($id) {
            $start = today();
            $end = today()->addDays(30);
            $counts = \App\Models\Booking::confirmedOverlapCountsForRange($id, $start->copy(), $end->copy());

            return collect($counts)
                ->filter(fn (int $used, string $date) => $used >= \App\Models\Booking::capacityForDate($id, \Illuminate\Support\Carbon::parse($date)))
                ->keys()
                ->values()
                ->all();
        });

        return response()->json([
            'success' => true,
            'data'    => $dates,
        ]);
    }

    /**
     * GET /api/stores/{id}/limited-dates -- tanggal (30 hari ke depan) yang
     * kapasitas instalasinya "hampir penuh": tinggal 1 slot dan toko punya
     * lebih dari 1 slot per hari. Hanya status, TANPA angka kapasitas (tidak
     * membuka data internal toko). Dipakai pemilih tanggal booking customer
     * untuk memberi penanda "Hampir penuh". Booking pending belum mengunci
     * slot, jadi ini perkiraan. Di-cache 60 detik karena endpoint publik.
     */
    public function limitedDates(int $id): JsonResponse
    {
        if (! \App\Models\Store::whereKey($id)->where('is_active', true)->exists()) {
            abort(404);
        }

        $dates = \Illuminate\Support\Facades\Cache::remember("store-limited-dates:{$id}", 60, function () use ($id) {
            $counts = \App\Models\Booking::confirmedOverlapCountsForRange($id, today(), today()->addDays(30));

            return collect($counts)
                ->filter(function (int $used, string $date) use ($id) {
                    $capacity = \App\Models\Booking::capacityForDate($id, \Illuminate\Support\Carbon::parse($date));

                    return $capacity > 1 && ($capacity - $used) === 1;
                })
                ->keys()
                ->values()
                ->all();
        });

        return response()->json(['success' => true, 'data' => $dates]);
    }
}