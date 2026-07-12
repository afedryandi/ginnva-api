<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
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
    private const STAFF_ROLES = ['super_admin', 'regional_admin', 'installer'];

    /**
     * POST /api/auth/detect-role
     *
     * Dipanggil dari layar login terpadu di mobile app SEBELUM user tahu
     * apakah dirinya customer atau staff — supaya app bisa otomatis
     * tampilkan alur yang tepat (password untuk staff, OTP untuk
     * customer) tanpa user perlu pilih menu login yang berbeda.
     *
     * Publik & tidak throttle ketat (cuma exists-check, tidak membocorkan
     * info sensitif — hanya "staff" atau "customer").
     */
    public function detectRole(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $isStaff = User::where('email', $request->email)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->exists();

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

        if (! $user->hasAnyRole(self::STAFF_ROLES)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak memiliki akses staff.'], 403);
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

    public function logout(Request $request)
    {
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

        $user = User::where('email', $request->email)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->first();

        // Selalu balas sukses terlepas dari email terdaftar atau tidak —
        // supaya endpoint ini tidak bisa dipakai untuk enumerasi akun
        // staff mana saja yang valid.
        if ($user) {
            $otp = OtpCode::generateFor($request->email, 'staff_reset_password');
            Mail::to($request->email)->send(new OtpMail($otp->code));
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

        $user = User::where('email', $request->email)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->first();

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
        ];
    }
}
