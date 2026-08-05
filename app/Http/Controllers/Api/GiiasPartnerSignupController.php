<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnershipInquiry;
use App\Models\User;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GiiasPartnerSignupController extends Controller
{
    /**
     * POST /api/giias/partner-signup
     *
     * "Become a Partner" di halaman /giias — dipakai oleh sales GINNVA
     * saat approach sales dealer mobil di GIIAS. BEDA dari
     * PartnershipInquiryController::submit() (pengajuan franchise) yang
     * tetap manual lewat review admin: di sini akun Partner + kode
     * referral dibuat LANGSUNG REAL-TIME saat submit, supaya sales
     * dealer bisa langsung dapat link referral mereka sendiri
     * (https://ginnva.id/giias?ref=KODE) tanpa menunggu approval.
     *
     * Tetap dicatat juga sebagai PartnershipInquiry (category='sales',
     * status='deal', partner_id langsung terisi) supaya tim Ginnva tetap
     * punya jejak siapa saja yang daftar & dari mana, tanpa mencampur
     * makna dengan pengajuan franchise yang statusnya masih 'new'.
     *
     * Login ke akun ini belum relevan sekarang (ginnva-mobile partner app
     * belum rilis) — password diisi acak & tidak diberitahukan ke sales;
     * begitu app rilis, gunakan alur "Lupa Password" yang sudah ada untuk
     * set password pertama kali.
     */
    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'phone'       => 'required|string|max:30',
            'email'       => 'required|email|max:255',
            'car_brand'   => 'required|string|max:255',
            'dealer_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $email = $request->email;

        // Idempoten — kalau email ini sudah pernah daftar jadi partner
        // (mis. submit dobel karena refresh halaman), kembalikan kode
        // referral yang sudah ada, bukan bikin akun baru/kena error unique.
        $existingPartner = Partner::whereHas('user', fn ($q) => $q->where('email', $email))->first();
        if ($existingPartner) {
            $link = $this->buildReferralLink($existingPartner->referral_code);

            return response()->json([
                'success' => true,
                'message' => 'Anda sudah terdaftar sebagai partner sebelumnya.',
                'data' => [
                    'referral_code' => $existingPartner->referral_code,
                    'referral_link' => $link,
                    'qr_data_uri'   => QrCodeService::generateDataUri($link, 400),
                ],
            ], 200);
        }

        // Email sudah dipakai akun lain (staff/customer/dst) yang bukan
        // partner — tidak bisa dipakai ulang karena users.email unique.
        if (User::where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Email ini sudah terdaftar di sistem Ginnva. Gunakan email lain.',
                'errors'  => ['email' => ['Email sudah digunakan.']],
            ], 422);
        }

        $businessName = $request->dealer_name
            ? "{$request->name} - {$request->dealer_name}"
            : $request->name;

        $partner = DB::transaction(function () use ($request, $email, $businessName) {
            $partner = Partner::createAccount([
                'business_name' => $businessName,
                'email'         => $email,
                'password'      => Str::random(20),
                'phone'         => $request->phone,
                'status'        => 'active',
            ]);

            PartnershipInquiry::create([
                'category'       => 'sales',
                'applicant_name' => $request->name,
                'phone_number'   => $request->phone,
                'email'          => $email,
                'car_brand'      => $request->car_brand,
                'dealer_name'    => $request->dealer_name,
                'status'         => 'deal',
                'partner_id'     => $partner->id,
                'notes'          => 'Dibuat otomatis lewat form Become a Partner di halaman /giias.',
            ]);

            return $partner;
        });

        $link = $this->buildReferralLink($partner->referral_code);

        return response()->json([
            'success' => true,
            'message' => 'Pendaftaran partner berhasil! Kode referral Anda sudah aktif.',
            'data' => [
                'referral_code' => $partner->referral_code,
                'referral_link' => $link,
                'qr_data_uri'   => QrCodeService::generateDataUri($link, 400),
            ],
        ], 201);
    }

    /**
     * GET /api/giias/partner-lookup/{code}
     *
     * Dipanggil server-to-server oleh Google Apps Script (bukan browser
     * customer) tiap kali form klaim customer di /giias disubmit dengan
     * referral code — supaya kolom "Sales Advisor"/"Brand (Referral)"/
     * "Dealer" di Google Sheet CRM terisi OTOMATIS saat itu juga, tanpa
     * rumus VLOOKUP atau tim Ginnva harus cek manual ke Filament.
     *
     * Publik (tidak perlu login) SENGAJA — Apps Script tidak punya cara
     * mudah untuk kirim token API. Cuma expose data yang memang sudah
     * dimaksudkan untuk dibagikan ke customer lewat kode referral itu
     * sendiri (nama sales, merek, dealer) — bukan data sensitif seperti
     * email/telepon/poin.
     */
    public function lookup(string $code)
    {
        $partner = Partner::where('referral_code', $code)->first();

        if (! $partner) {
            return response()->json([
                'success' => false,
                'message' => 'Kode referral tidak ditemukan.',
            ], 404);
        }

        // Ambil data merek/dealer dari pengajuan "sales" yang terhubung
        // (diisi saat daftar lewat Become a Partner). Partner yang dibuat
        // manual oleh admin lewat Filament (bukan lewat form) tidak akan
        // punya baris ini — car_brand/dealer_name akan null, tapi nama
        // tetap terisi dari business_name.
        $inquiry = PartnershipInquiry::where('partner_id', $partner->id)
            ->where('category', 'sales')
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'name'        => $inquiry?->applicant_name ?? $partner->business_name,
                'car_brand'   => $inquiry?->car_brand,
                'dealer_name' => $inquiry?->dealer_name,
            ],
        ]);
    }

    private function buildReferralLink(string $code): string
    {
        $base = config('app.frontend_url', 'https://ginnva.id');

        return rtrim($base, '/') . '/giias?ref=' . $code;
    }
}
