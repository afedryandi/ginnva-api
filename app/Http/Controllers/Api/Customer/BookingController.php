<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    /**
     * GET /api/customer/bookings
     * Daftar booking milik customer yang login (我的预约).
     */
    public function index(Request $request)
    {
        $bookings = $request->user('customer')
            ->bookings()
            ->with('store')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bookings,
        ]);
    }

    /**
     * POST /api/customer/bookings
     * Buat booking baru — wajib login (customer_id diambil dari token,
     * tidak dari input, supaya tidak bisa booking atas nama orang lain).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id'          => 'required|exists:stores,id',
            'service_type'      => 'required|string|max:255',
            'product_kaca_film' => 'sometimes|boolean',
            'product_ppf'       => 'sometimes|boolean',
            'preferred_date'    => 'required|date|after_or_equal:today',
            'preferred_time'    => 'nullable|string|max:50',
            'notes'             => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Tanggal yang diblokir toko (lihat /api/stores/{id}/blocked-dates)
        // sebelumnya cuma dipakai buat disable tanggal di UI picker mobile
        // — TIDAK ditegakkan di sini, jadi hit langsung ke endpoint ini
        // (atau race condition tanggal baru diblokir setelah picker dibuka)
        // bisa lolos booking di tanggal yang sudah ditutup toko.
        $isBlocked = BlockedDate::where('store_id', $request->store_id)
            ->whereDate('date', $request->preferred_date)
            ->exists();

        if ($isBlocked) {
            return response()->json([
                'success' => false,
                'message' => 'Tanggal yang dipilih sedang tidak tersedia untuk toko ini. Silakan pilih tanggal lain.',
                'errors'  => ['preferred_date' => ['Tanggal ini sudah diblokir toko.']],
            ], 422);
        }

        // Mobile app sudah nonaktifkan tanggal di hari toko tutup (lihat
        // jam operasional per-toko), tapi itu cuma UI — ditegakkan lagi di
        // sini supaya hit langsung ke endpoint ini (atau versi app lama
        // yang belum tahu fitur ini) tidak bisa lolos booking di hari
        // toko libur.
        $store = Store::find($request->store_id);

        if ($store?->isClosedOn(Carbon::parse($request->preferred_date))) {
            return response()->json([
                'success' => false,
                'message' => 'Toko tutup pada hari yang dipilih. Silakan pilih tanggal lain.',
                'errors'  => ['preferred_date' => ['Toko tutup pada hari ini.']],
            ], 422);
        }

        $booking = Booking::create([
            'customer_id'       => $request->user('customer')->id,
            'store_id'          => $request->store_id,
            'service_type'      => $request->service_type,
            'product_kaca_film' => $request->boolean('product_kaca_film'),
            'product_ppf'       => $request->boolean('product_ppf'),
            'preferred_date'    => $request->preferred_date,
            'preferred_time'    => $request->preferred_time,
            'notes'             => $request->notes,
            'source'            => 'app',
            'status'            => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking berhasil diajukan. Toko akan menghubungi Anda untuk konfirmasi.',
            'data' => $booking,
        ], 201);
    }
}
