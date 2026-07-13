<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\ReferralPointService;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use RuntimeException;

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

    /**
     * POST /api/staff/bookings/{id}/complete
     *
     * Ditandai staff toko saat customer selesai bayar di toko (pembayaran
     * di luar sistem — cash/EDC/dll). Kalau customer datang lewat kode
     * referral partner, staff isi `referral_code` — sistem otomatis kasih
     * poin ke partner & customer (lihat ReferralPointService). Kalau
     * customer punya voucher, staff isi `voucher_code` — nominalnya
     * dipotong dari `transaction_amount` SEBELUM poin referral dihitung
     * (poin dihitung dari nominal yang benar-benar dibayar). Keduanya
     * opsional — booking tanpa referral/voucher tetap bisa di-complete
     * seperti biasa.
     */
    public function complete(
        Request $request,
        int $id,
        ReferralPointService $referralPoints,
        VoucherService $vouchers
    ) {
        $user = $request->user('api');

        $request->validate([
            'transaction_amount' => 'nullable|numeric|min:0',
            'referral_code'      => 'nullable|string|max:20',
            'voucher_code'       => 'nullable|string|max:20',
        ]);

        $booking = Booking::findOrFail($id);

        if (! $user->hasRole('super_admin') && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        $booking->update([
            'status'             => 'completed',
            'current_stage'      => 'completed',
            'transaction_amount' => $request->transaction_amount,
            'referral_code'      => $request->referral_code,
        ]);

        try {
            $voucherClaim = $vouchers->redeem($booking, $request->voucher_code);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $discount = 0;
        if ($voucherClaim) {
            $discount = (float) $voucherClaim->voucher->discount_amount;
            $booking->transaction_amount = max(0, $booking->transaction_amount - $discount);
            $booking->voucher_claim_id = $voucherClaim->id;
            $booking->save();
        }

        $partner = $referralPoints->awardForBooking($booking->fresh());

        $messages = [];
        if ($discount > 0) {
            $messages[] = 'Voucher Rp' . number_format($discount, 0, ',', '.') . ' berhasil dipakai.';
        }
        if ($partner) {
            $messages[] = "Poin referral diberikan ke partner {$partner->business_name}.";
        }

        return response()->json([
            'success' => true,
            'data'    => $booking->fresh(),
            'message' => $messages ? implode(' ', $messages) : 'Booking selesai.',
        ]);
    }
}
