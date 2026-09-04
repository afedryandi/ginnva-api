<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\DeviceToken;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Login untuk staff (admin toko / super_admin) di mobile app — akun yang
 * sama persis dengan yang dipakai login ke Filament (App\Models\User),
 * TIDAK ada sistem akun terpisah. Pakai guard 'api' (JWT) yang sudah ada
 * di config/auth.php tapi sebelumnya belum dipakai untuk mobile.
 */
class AuthController extends Controller
{
    /**
     * POST /api/auth/detect-role
     *
     * Dipanggil dari layar login terpadu di mobile app SEBELUM user tahu
     * apakah dirinya customer atau staff — supaya app bisa otomatis
     * tampilkan alur yang tepat (password untuk staff, OTP untuk
     * customer) tanpa user perlu pilih menu login yang berbeda.
     *
     * SENGAJA cuma cek "ada barisnya di tabel users atau tidak" — TIDAK
     * dibatasi ke daftar role tertentu. Tabel `users` ini memang khusus
     * akun staff/admin/partner (customer punya tabel & login OTP sendiri
     * yang terpisah total), jadi role apa pun yang ada di baris itu tetap
     * berarti "staff". Ini juga supaya role baru yang dibuat admin sendiri
     * lewat Filament (lihat RoleResource) otomatis bisa login tanpa perlu
     * ada yang update kode di sini setiap kali ada role baru.
     *
     * Publik & tidak throttle ketat (cuma exists-check, tidak membocorkan
     * info sensitif — hanya "staff" atau "customer").
     */
    public function detectRole(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $isStaff = User::where('email', $request->email)->exists();

        return response()->json(['role' => $isStaff ? 'staff' : 'customer']);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['success' => false, 'message' => 'Email atau password salah.'], 401);
        }

        // Akun staff yang dinonaktifkan admin (User::is_active, lihat menu
        // "User" -> aksi "Nonaktifkan") tidak boleh login ke mobile app —
        // gate yang sama juga berlaku di canAccessPanel() untuk sisi
        // Filament, diduplikasi di sini karena guard 'api' tidak lewat
        // canAccessPanel() sama sekali.
        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda sudah dinonaktifkan. Hubungi admin jika ini keliru.',
            ], 403);
        }

        // Partner yang dinonaktifkan admin (Partner::status) sebelumnya
        // masih bisa login & dapat token baru — status cuma ditegakkan
        // saat earning poin referral (lihat ReferralPointService), bukan
        // di gerbang akses ini. Staff/role lain tidak punya relasi
        // partner sama sekali jadi tidak terdampak.
        if ($user->partner && $user->partner->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Akun partner ini sedang nonaktif. Hubungi tim Ginnva untuk informasi lebih lanjut.',
            ], 403);
        }

        $token = Auth::guard('api')->login($user);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user'  => $this->transform($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['success' => true, 'user' => $this->transform($request->user('api'))]);
    }

    /**
     * `push_token` OPSIONAL — sama pola dengan Customer\AuthController::
     * logout(), unlink device_tokens device ini dari akun staff/partner
     * yang logout, supaya di HP bersama/demo unit toko tidak ada
     * notifikasi bertarget yang nyasar ke akun yang sudah logout.
     */
    public function logout(Request $request)
    {
        $user = $request->user('api');

        if ($request->filled('push_token') && $user) {
            DeviceToken::where('token', $request->push_token)
                ->where('user_id', $user->id)
                ->update(['user_id' => null]);
        }

        Auth::guard('api')->logout();

        return response()->json(['success' => true, 'message' => 'Logout berhasil.']);
    }

    /**
     * POST /api/staff/auth/forgot-password
     *
     * Kirim kode verifikasi 6 digit ke email staff — dipakai buat reset
     * password dari mobile app tanpa perlu link email/halaman web
     * terpisah (staff cuma pakai mobile app, bukan browser). Reuse
     * infrastruktur OtpCode yang sama dengan login OTP customer, tapi
     * pakai channel berbeda ('staff_reset_password') supaya tidak
     * tercampur dengan kode OTP login customer di email yang sama.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        // Selalu balas sukses terlepas dari email terdaftar atau tidak —
        // supaya endpoint ini tidak bisa dipakai untuk enumerasi akun
        // staff mana saja yang valid.
        if ($user) {
            $otp = OtpCode::generateFor($request->email, 'staff_reset_password');

            // Tetap balas sukses generik di luar blok ini walau kirim
            // gagal (mis. domain email staff bermasalah) — konsisten
            // dengan desain anti-enumerasi di atas, cukup dicatat di log.
            try {
                Mail::to($request->email)->send(new OtpMail($otp->code));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('[OtpMail/StaffReset] Gagal kirim: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Jika email terdaftar sebagai akun staff, kode verifikasi telah dikirim.',
        ]);
    }

    /**
     * POST /api/staff/auth/reset-password
     * Verifikasi kode dari forgotPassword() lalu set password baru.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'code'     => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $isValid = OtpCode::verify($request->email, 'staff_reset_password', $request->code);

        if (! $isValid) {
            return response()->json([
                'success' => false,
                'message' => 'Kode verifikasi salah atau sudah kedaluwarsa.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Akun tidak ditemukan.'], 404);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil diubah. Silakan masuk dengan password baru.',
        ]);
    }

    private function transform(User $user): array
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'email'    => $user->email,
            'role'     => $user->getRoleNames()->first(),
            'store_id' => $user->store_id,
            // Dipakai app buat putuskan halaman awal setelah login —
            // lihat User::hasBookingAccess()/hasInventoryAccess().
            'has_booking_access'   => $user->hasBookingAccess(),
            'has_quotation_access' => $user->hasQuotationAccess(),
            'has_inventory_access' => $user->hasInventoryAccess(),
            // Granular per-submenu — dipakai buat filter menu MANA yang
            // ditampilkan di hub Inventaris/menu kubus, supaya staff yang
            // cuma dicentang akses sebagian tidak lihat menu yang bakal
            // ditolak (403) begitu dibuka.
            'has_ppf_wf_access'    => $user->hasPpfWfAccess(),
            'has_material_access'  => $user->hasMaterialAccess(),
            'has_asset_access'     => $user->hasAssetAccess(),
            'has_consumable_access' => $user->hasConsumableAccess(),
            'has_material_memo_access' => $user->hasMaterialMemoAccess(),
            'has_purchase_request_access' => $user->hasPurchaseRequestAccess(),
        ];
    }
}