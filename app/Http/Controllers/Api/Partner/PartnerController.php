<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\RewardRedemption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PartnerController extends Controller
{
    private function partnerOrAbort(Request $request): Partner
    {
        $partner = $request->user('api')->partner;

        abort_if(! $partner, 403, 'Akun ini bukan akun partner.');

        // Login sudah diblok untuk partner nonaktif (lihat Staff\AuthController::login()),
        // tapi token lama yang diterbitkan SEBELUM dinonaktifkan tetap valid
        // sampai expired — cek ulang di sini supaya akses langsung putus.
        abort_if($partner->status !== 'active', 403, 'Akun partner ini sedang nonaktif.');

        return $partner;
    }

    /**
     * GET /api/partner/me
     * Requires: auth:api, role partner
     */
    public function me(Request $request)
    {
        $partner = $this->partnerOrAbort($request);

        return response()->json([
            'success' => true,
            'data' => [
                'id'             => $partner->id,
                'business_name'  => $partner->business_name,
                'phone'          => $partner->phone,
                'referral_code'  => $partner->referral_code,
                'points_balance' => $partner->points_balance,
                'status'         => $partner->status,
                'joined_at'      => $partner->created_at,
            ],
        ]);
    }

    /**
     * GET /api/partner/points
     * Requires: auth:api, role partner
     */
    public function points(Request $request)
    {
        $partner = $this->partnerOrAbort($request);

        $transactions = PartnerPointTransaction::where('partner_id', $partner->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success'      => true,
            'balance'      => $partner->points_balance,
            'transactions' => $transactions,
        ]);
    }

    /**
     * GET /api/partner/redemptions
     * Requires: auth:api, role partner
     */
    public function redemptions(Request $request)
    {
        $partner = $this->partnerOrAbort($request);

        $redemptions = RewardRedemption::with('reward')
            ->where('redeemer_type', 'partner')
            ->where('redeemer_id', $partner->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $redemptions]);
    }

    /**
     * GET /api/partner/referrals
     * Requires: auth:api, role partner
     *
     * Riwayat booking yang terhubung ke partner ini lewat kode referral —
     * beda dari /points (ledger poin generik), ini menampilkan SIAPA yang
     * direferensikan & booking apa persisnya, supaya partner bisa lihat
     * dampak konkret dari kode referral yang dibagikan, bukan cuma angka
     * poin. `Booking::partner_id` hanya terisi setelah
     * ReferralPointService::awardForBooking() berhasil (lihat komentar di
     * sana) — jadi tiap baris di sini SUDAH PASTI sudah dapat poin,
     * tidak perlu status/filter tambahan.
     */
    public function referrals(Request $request)
    {
        $partner = $this->partnerOrAbort($request);

        $bookings = $partner->bookings()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Ambil poin per booking dari ledger asli (bukan dihitung ulang
        // dari transaction_amount) — supaya angka yang ditampilkan selalu
        // sama persis dengan yang sudah tercatat di /points, walau rumus
        // konversi poin berubah di masa depan.
        $pointsByBooking = PartnerPointTransaction::where('partner_id', $partner->id)
            ->where('reference_type', 'booking')
            ->pluck('points', 'reference_id');

        return response()->json([
            'success' => true,
            'data' => $bookings->map(fn (Booking $b) => [
                'id'                 => $b->id,
                'booking_number'     => $b->booking_number,
                'customer_name'      => $b->customer_name,
                'status'             => $b->status,
                'transaction_amount' => $b->transaction_amount !== null ? (float) $b->transaction_amount : null,
                'points_earned'      => $pointsByBooking[$b->id] ?? 0,
                'created_at'         => $b->created_at,
            ]),
        ]);
    }

    /**
     * PUT /api/partner/profile
     * Requires: auth:api, role partner
     *
     * Update nama usaha & nomor telepon sendiri — sebelumnya partner
     * tidak punya jalur self-service sama sekali untuk ini, cuma bisa
     * diubah admin lewat Filament. Email & referral_code SENGAJA tidak
     * bisa diubah dari sini (email = identitas login, referral_code
     * dikelola/di-generate sistem).
     */
    public function updateProfile(Request $request)
    {
        $partner = $this->partnerOrAbort($request);

        $validator = Validator::make($request->all(), [
            'business_name' => 'required|string|max:255',
            'phone'         => 'nullable|string|max:30|unique:partners,phone,' . $partner->id,
        ], [
            'phone.unique' => 'Nomor telepon ini sudah terdaftar di akun partner lain.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $partner->update([
            'business_name' => $request->business_name,
            'phone'         => $request->phone,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui.',
            'data' => [
                'id'             => $partner->id,
                'business_name'  => $partner->business_name,
                'phone'          => $partner->phone,
                'referral_code'  => $partner->referral_code,
                'points_balance' => $partner->points_balance,
                'status'         => $partner->status,
                'joined_at'      => $partner->created_at,
            ],
        ]);
    }

    /**
     * POST /api/partner/change-password
     * Requires: auth:api, role partner
     *
     * Ganti password saat masih login (beda dari alur "Lupa Password" di
     * layar login yang berbasis OTP email untuk yang lupa/logged-out) —
     * wajib verifikasi password lama dulu supaya sesi yang ke-hijack
     * tidak bisa diam-diam mengambil alih akun dengan ganti password
     * tanpa tahu password aslinya.
     */
    public function changePassword(Request $request)
    {
        $partner = $this->partnerOrAbort($request);
        $user = $request->user('api');

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password lama yang Anda masukkan salah.',
            ], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil diubah.',
        ]);
    }
}
