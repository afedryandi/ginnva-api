<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    /**
     * GET /api/staff/bookings
     * Scoping per role:
     * - super_admin (Direksi)        : semua booking, semua toko.
     * - regional_admin (Store Mgr)   : booking toko sendiri saja — sama
     *                                   persis scoping BookingResource di
     *                                   Filament, supaya datanya konsisten.
     * - installer (Tim Instalasi)    : HANYA booking yang di-assign ke
     *                                   dirinya (installer_user_id),
     *                                   bukan seluruh booking toko.
     */
    public function index(Request $request)
    {
        $user = $request->user('api');

        $query = Booking::query()->with(['customer:id,name,phone_number', 'store:id,name'])
            ->orderByDesc('preferred_date');

        if ($user->hasRole('installer')) {
            $query->where('installer_user_id', $user->id);
        } elseif (! $user->hasRole('super_admin')) {
            $query->where('store_id', $user->store_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $bookings = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $bookings->items(),
            'meta'    => [
                'current_page' => $bookings->currentPage(),
                'last_page'    => $bookings->lastPage(),
                'total'        => $bookings->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user('api');

        $booking = Booking::with(['customer:id,name,phone_number', 'store:id,name'])
            ->findOrFail($id);

        if ($user->hasRole('installer')) {
            if ($booking->installer_user_id !== $user->id) {
                abort(403, 'Booking ini tidak ditugaskan ke Anda.');
            }
        } elseif (! $user->hasRole('super_admin') && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        return response()->json(['success' => true, 'data' => $booking]);
    }
}
