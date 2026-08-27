<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnershipInquiry;
use App\Models\User;
use App\Services\QrCodeService;
use App\Services\WhatsAppService;
use Illuminate\Database\QueryException;
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
            // Email OPSIONAL — kebanyakan sales daftar cuma kasih nama +
            // WA saat di lapangan GIIAS, tidak sempat/mau kasih email.
            // Nomor WA yang sekarang jadi identitas utama dedupe (lihat
            // di bawah), bukan email lagi.
            'email'       => 'nullable|email|max:255',
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

        $email = $request->email; // boleh null

        // Normalisasi ke format 62xxx — tanpa ini, "08123..." dan
        // "+62 812-3..." dianggap 2 nomor beda walau orangnya sama, dan
        // dedupe di bawah gagal mendeteksi submit dobel.
        $phone = WhatsAppService::normalizePhoneNumber($request->phone);

        // Idempoten — dedupe utama sekarang lewat NOMOR WA (data yang
        // paling reliable diisi), email cuma dicek tambahan kalau memang
        // diisi. Mencegah submit dobel (mis. refresh halaman) bikin akun
        // partner baru/duplikat. Lapisan terakhir kalau 2 request nyaris
        // bersamaan lolos cek ini juga (race) ada di UNIQUE index kolom
        // phone — ditangkap lewat QueryException di bawah, bukan
        // dibiarkan jadi error 500 mentah.
        $existingPartner = Partner::where('phone', $phone)->first();
        if (! $existingPartner && $email) {
            $existingPartner = Partner::whereHas('user', fn ($q) => $q->where('email', $email))->first();
        }
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
        // Cuma relevan kalau sales memang isi email.
        if ($email && User::where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Email ini sudah terdaftar di sistem Ginnva. Gunakan email lain.',
                'errors'  => ['email' => ['Email sudah digunakan.']],
            ], 422);
        }

        $businessName = $request->dealer_name
            ? "{$request->name} - {$request->dealer_name}"
            : $request->name;

        // users.email WAJIB terisi & unique di level database (dipakai
        // login) — kalau sales tidak kasih email, akun tetap perlu 1
        // nilai unik di kolom itu. Login lewat akun ini belum relevan
        // sekarang (app partner belum rilis, lihat komentar class di
        // atas), jadi placeholder ini aman — begitu app partner rilis,
        // alur "Lupa Password"-nya butuh email ASLI, itu akan jadi
        // masalah operasional terpisah (partner tanpa email perlu
        // dihubungi manual untuk lengkapi datanya).
        $accountEmail = $email ?: $this->generatePlaceholderEmail($phone);

        try {
            $partner = DB::transaction(function () use ($request, $phone, $accountEmail, $email, $businessName) {
                $partner = Partner::createAccount([
                    'business_name' => $businessName,
                    'email'         => $accountEmail,
                    'password'      => Str::random(20),
                    'phone'         => $phone,
                    'status'        => 'active',
                    'source'        => 'giias',
                    'type'          => 'partner',
                ]);

                PartnershipInquiry::create([
                    'category'       => 'sales',
                    // Bedain dari pengajuan yang masuk lewat /partner
                    // (landing page umum, lihat PartnerSignupController)
                    // — keduanya sama-sama category='sales'.
                    'source'         => 'giias',
                    'applicant_name' => $request->name,
                    'phone_number'   => $phone,
                    // Simpan email ASLI (bisa null) di sini — BUKAN
                    // $accountEmail — supaya admin tidak lihat placeholder
                    // palsu di catatan pengajuan.
                    'email'          => $email,
                    'car_brand'      => $request->car_brand,
                    'dealer_name'    => $request->dealer_name,
                    'status'         => 'deal',
                    'partner_id'     => $partner->id,
                    'notes'          => 'Dibuat otomatis lewat form Become a Partner di halaman /giias.',
                ]);

                return $partner;
            });
        } catch (QueryException $e) {
            // UNIQUE index kolom phone menangkap race yang lolos cek
            // exists() di atas (2 submit nyaris bersamaan) — nomor WA-nya
            // PASTI sudah terdaftar (baru saja dibuat request lain),
            // kembalikan kode referral yang sudah ada alih-alih error 500
            // mentah ke customer.
            $racedPartner = Partner::where('phone', $phone)->first();

            if (! $racedPartner) {
                throw $e; // bukan soal race phone — biar tetap kelihatan sebagai error asli
            }

            $link = $this->buildReferralLink($racedPartner->referral_code);

            return response()->json([
                'success' => true,
                'message' => 'Anda sudah terdaftar sebagai partner sebelumnya.',
                'data' => [
                    'referral_code' => $racedPartner->referral_code,
                    'referral_link' => $link,
                    'qr_data_uri'   => QrCodeService::generateDataUri($link, 400),
                ],
            ], 200);
        }

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

    /**
     * Placeholder unik untuk users.email saat sales tidak isi email —
     * domain "no-reply.ginnva.id" dipakai supaya gampang dikenali di
     * Filament/database mana yang akun beneran vs placeholder (lihat
     * juga PartnerResource yang kasih tanda visual untuk pola ini).
     */
    private function generatePlaceholderEmail(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: 'x';

        do {
            $candidate = "sales-{$digits}-" . Str::lower(Str::random(4)) . '@no-reply.ginnva.id';
        } while (User::where('email', $candidate)->exists());

        return $candidate;
    }
}