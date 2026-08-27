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

/**
 * Kembaran GiiasPartnerSignupController — duplikat SENGAJA (bukan
 * di-generalize jadi 1 kelas dengan parameter) supaya alur /giias yang
 * sudah live & QR-nya sudah dicetak/dibagikan tidak ikut ke-risiko kalau
 * ada perubahan di sini. Beda utamanya cuma 2: link yang dibuat mengarah
 * ke /partner (bukan /giias, sesuai permintaan "tidak ada kata giias
 * nya lagi"), dan PartnershipInquiry ditandai source='partner' supaya
 * tetap bisa dibedakan dari pengajuan /giias walau nulis ke tabel yang
 * sama. Landing page ini terbuka untuk event/audience apa pun, tidak
 * terikat 1 pameran tertentu seperti /giias.
 */
class PartnerSignupController extends Controller
{
    /**
     * POST /api/partner-signup
     */
    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'phone'       => 'required|string|max:30',
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

        $phone = WhatsAppService::normalizePhoneNumber($request->phone);

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

        $accountEmail = $email ?: $this->generatePlaceholderEmail($phone);

        try {
            $partner = DB::transaction(function () use ($request, $phone, $accountEmail, $email, $businessName) {
                $partner = Partner::createAccount([
                    'business_name' => $businessName,
                    'email'         => $accountEmail,
                    'password'      => Str::random(20),
                    'phone'         => $phone,
                    'status'        => 'active',
                    'source'        => 'partner',
                    'type'          => 'partner',
                ]);

                PartnershipInquiry::create([
                    'category'       => 'sales',
                    'source'         => 'partner',
                    'applicant_name' => $request->name,
                    'phone_number'   => $phone,
                    'email'          => $email,
                    'car_brand'      => $request->car_brand,
                    'dealer_name'    => $request->dealer_name,
                    'status'         => 'deal',
                    'partner_id'     => $partner->id,
                    'notes'          => 'Dibuat otomatis lewat form Become a Partner di halaman /partner.',
                ]);

                return $partner;
            });
        } catch (QueryException $e) {
            $racedPartner = Partner::where('phone', $phone)->first();

            if (! $racedPartner) {
                throw $e;
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
     * GET /api/partner-lookup/{code}
     *
     * Sama tujuannya dengan GiiasPartnerSignupController::lookup() —
     * dipanggil Google Apps Script dari SHEET YANG SAMA (Apps Script-nya
     * di-update untuk kirim field "source" supaya baris /giias vs
     * /partner tetap bisa dibedakan di sheet itu juga, terlepas dari
     * endpoint lookup mana yang dipanggil). Endpoint terpisah ini
     * sebenarnya OPSIONAL secara fungsi (kode referral unik lintas
     * semua Partner, endpoint /giias/partner-lookup/{code} yang sudah
     * ada juga akan menemukan partner yang daftar lewat /partner) —
     * disediakan juga di sini murni supaya penamaan endpoint tidak
     * membingungkan kalau dibaca terpisah dari konteks /giias.
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

        return rtrim($base, '/') . '/partner?ref=' . $code;
    }

    private function generatePlaceholderEmail(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: 'x';

        do {
            $candidate = "sales-{$digits}-" . Str::lower(Str::random(4)) . '@no-reply.ginnva.id';
        } while (User::where('email', $candidate)->exists());

        return $candidate;
    }
}