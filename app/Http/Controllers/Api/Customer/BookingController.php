<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
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
            'store_id'        => 'required|exists:stores,id',
            'service_type'    => 'required|string|max:255',
            'preferred_date'  => 'required|date|after_or_equal:today',
            'preferred_time'  => 'nullable|string|max:50',
            'notes'           => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $booking = Booking::create([
            'customer_id'     => $request->user('customer')->id,
            'store_id'        => $request->store_id,
            'service_type'    => $request->service_type,
            'preferred_date'  => $request->preferred_date,
            'preferred_time'  => $request->preferred_time,
            'notes'           => $request->notes,
            'status'          => 'pending',
        ]);

        return response()->json([
            'message' => 'Booking berhasil diajukan. Toko akan menghubungi Anda untuk konfirmasi.',
            'data' => $booking,
        ], 201);
    }
}
