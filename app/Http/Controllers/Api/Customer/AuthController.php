<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\Customer;
use App\Models\OtpCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * POST /api/customer/auth/request-otp
     *
     * Kirim kode OTP ke email. Dipakai untuk login DAN register sekaligus
     * — tidak ada langkah "daftar" terpisah; kalau email belum punya
     * akun, akun baru otomatis dibuat saat verify-otp berhasil (lihat
     * verifyOtp()). Ini mengurangi friksi dibanding form
     * register/login terpisah, sesuai pola umum OTP-based auth di mobile app.
     */
    public function requestOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $otp = OtpCode::generateFor($request->email, 'email');

        // MAIL_MAILER masih 'log' di environment default — pastikan SMTP
        // asli sudah disetel di .env sebelum production, kalau tidak
        // email OTP hanya akan tertulis di storage/logs/laravel.log,
        // tidak benar-benar terkirim ke inbox customer.
        Mail::to($request->email)->send(new OtpMail($otp->code));

        return response()->json([
            'message' => 'Kode verifikasi telah dikirim ke email Anda.',
        ]);
    }

    /**
     * POST /api/customer/auth/verify-otp
     *
     * Verifikasi kode OTP. Kalau benar: ambil akun yang sudah ada
     * (login) atau buat akun baru (register) untuk email tersebut,
     * lalu kembalikan JWT token guard 'customer'.
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code'  => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $isValid = OtpCode::verify($request->email, 'email', $request->code);

        if (! $isValid) {
            return response()->json([
                'message' => 'Kode verifikasi salah atau sudah kedaluwarsa.',
            ], 422);
        }

        $customer = Customer::firstOrCreate(
            ['email' => $request->email],
            ['email_verified_at' => now()]
        );

        if (! $customer->email_verified_at) {
            $customer->update(['email_verified_at' => now()]);
        }

        $token = JWTAuth::fromUser($customer);

        return response()->json([
            'message' => 'Berhasil masuk.',
            'token'   => $token,
            'data'    => $customer,
        ]);
    }

    /**
     * GET /api/customer/auth/me
     * Profil customer yang sedang login (dari token).
     */
    public function me(Request $request)
    {
        return response()->json([
            'data' => $request->user('customer'),
        ]);
    }

    /**
     * PUT /api/customer/auth/profile
     * Update nama (dan nanti nomor HP, setelah WA OTP aktif).
     */
    public function updateProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $customer = $request->user('customer');
        $customer->update(['name' => $request->name]);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'data' => $customer,
        ]);
    }

    /**
     * POST /api/customer/auth/logout
     */
    public function logout(Request $request)
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json([
            'message' => 'Berhasil keluar.',
        ]);
    }
}
