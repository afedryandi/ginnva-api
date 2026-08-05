<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Mail\WelcomeMail;
use App\Models\Customer;
use App\Models\DeviceToken;
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
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $otp = OtpCode::generateFor($request->email, 'email');

        // MAIL_MAILER masih 'log' di environment default — pastikan SMTP
        // asli sudah disetel di .env sebelum production, kalau tidak
        // email OTP hanya akan tertulis di storage/logs/laravel.log,
        // tidak benar-benar terkirim ke inbox customer.
        try {
            Mail::to($request->email)->send(new OtpMail($otp->code));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[OtpMail] Gagal kirim: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim kode verifikasi. Periksa kembali alamat email Anda atau coba lagi nanti.',
            ], 422);
        }

        return response()->json([
            'success' => true,
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
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $isValid = OtpCode::verify($request->email, 'email', $request->code);

        if (! $isValid) {
            return response()->json([
                'success' => false,
                'message' => 'Kode verifikasi salah atau sudah kedaluwarsa.',
            ], 422);
        }

        $customer = Customer::firstOrCreate(
            ['email' => $request->email],
            ['email_verified_at' => now()]
        );

        $isNew = $customer->wasRecentlyCreated;

        if (! $customer->email_verified_at) {
            $customer->update(['email_verified_at' => now()]);
        }

        // Welcome email hanya untuk akun baru
        if ($isNew) {
            try {
                Mail::to($customer->email)->send(new WelcomeMail($customer));
            } catch (\Exception $e) {
                // Jangan crash verifyOtp kalau email gagal terkirim
                \Illuminate\Support\Facades\Log::warning('[WelcomeMail] Gagal kirim: ' . $e->getMessage());
            }
        }

        $token = JWTAuth::fromUser($customer);

        return response()->json([
            'success'  => true,
            'message'  => 'Berhasil masuk.',
            'token'    => $token,
            'is_new'   => $isNew,
            'data'     => $customer,
        ]);
    }

    /**
     * GET /api/customer/auth/me
     * Profil customer yang sedang login (dari token).
     */
    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $request->user('customer'),
        ]);
    }

    /**
     * PUT /api/customer/auth/profile
     * Update nama dan nomor WhatsApp. `referral_code` OPSIONAL — kode
     * referral customer LAIN (bukan Partner), diisi customer baru saat
     * Complete Profile kalau diajak teman. Cuma bisa di-set SEKALI (kalau
     * sudah pernah terhubung, input berikutnya diabaikan) supaya tidak
     * bisa diubah-ubah untuk mengakali sumber poin bonus referral.
     */
    public function updateProfile(Request $request)
    {
        $customer = $request->user('customer');

        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            // Sebelumnya tidak dicek unik di sini — bentrok nomor WA lolos
            // sampai ke DB unique constraint dan errornya (SQL mentah, host
            // DB, dll) bocor ke user lewat handler generik. Divalidasi di
            // sini dulu supaya pesannya jelas & tidak pernah nyampe ke DB.
            'phone_number'  => 'required|string|max:20|unique:customers,phone_number,' . $customer->id,
            'referral_code' => 'nullable|string|max:20',
        ], [
            'phone_number.unique' => 'Nomor WhatsApp ini sudah terdaftar di akun lain.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = [
            'name'         => $request->name,
            'phone_number' => $request->phone_number,
        ];

        if ($request->filled('referral_code') && ! $customer->referred_by_customer_id) {
            $referrer = Customer::whereRaw('UPPER(referral_code) = ?', [strtoupper($request->referral_code)])
                ->where('id', '!=', $customer->id)
                ->first();

            if (! $referrer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode referral tidak ditemukan.',
                ], 422);
            }

            $data['referred_by_customer_id'] = $referrer->id;
        }

        $customer->update($data);

        return response()->json([
            'success' => true,
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
            'success' => true,
            'message' => 'Berhasil keluar.',
        ]);
    }

    /**
     * DELETE /api/customer/auth/account
     *
     * Wajib ada per kebijakan Google Play (app dengan sistem akun harus
     * sediakan cara hapus akun). Row Customer TIDAK dihapus (booking,
     * warranty, transaksi poin tetap terikat customer_id untuk kebutuhan
     * operasional/legal toko) — sebagai gantinya semua PII dianonimkan
     * dan `deleted_at` diisi sebagai penanda. email/phone_number di-null
     * -kan (keduanya nullable+unique di skema) supaya alamat itu bisa
     * dipakai lagi untuk registrasi baru tanpa bentrok unique constraint.
     */
    public function deleteAccount(Request $request)
    {
        $customer = $request->user('customer');

        DeviceToken::where('customer_id', $customer->id)->delete();

        $customer->update([
            'name'               => null,
            'email'              => null,
            'phone_number'       => null,
            'email_verified_at'  => null,
            'phone_verified_at'  => null,
            'deleted_at'         => now(),
        ]);

        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json([
            'success' => true,
            'message' => 'Akun dan data pribadi Anda berhasil dihapus.',
        ]);
    }
}