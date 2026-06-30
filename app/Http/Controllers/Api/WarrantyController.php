<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warranty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;
use Tymon\JWTAuth\Facades\JWTAuth;

class WarrantyController extends Controller
{
    // POST /api/warranty/submit
    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_name'     => 'required|string|max:255',
            'phone_number'      => 'required|string|max:30',
            'car_plate'         => 'required|string|max:20',
            'car_type'          => 'required|string|max:255',
            'product_series'    => 'required|string|max:255',
            'installation_date' => 'required|date',
            'expiry_date'       => 'required|date|after:installation_date',
            'dealer_name'       => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $warrantyCode = 'GNV-' . strtoupper(Str::random(10));

        // Endpoint ini SENGAJA tetap publik (tidak wajib login) — guest
        // tanpa akun tetap bisa daftar garansi seperti biasa. Tapi kalau
        // request menyertakan Bearer token customer yang valid, warranty
        // ini otomatis terhubung ke akun itu supaya muncul di "Garansi
        // Saya" (我的质保) di mobile app. parseToken() dibungkus try-catch
        // karena token bisa saja tidak ada sama sekali, kedaluwarsa, atau
        // tidak valid — semua kondisi itu HARUS tetap lanjut sebagai guest,
        // bukan menggagalkan submission warranty.
        $customerId = null;
        try {
            $customer = JWTAuth::setToken(JWTAuth::getToken())->authenticate();
            $customerId = $customer?->id;
        } catch (\Throwable $e) {
            // Tidak ada token / token tidak valid -> lanjut sebagai guest.
        }

        // Catatan QA Management: submission baru TIDAK langsung aktif.
        // status tetap 'active' sebagai nilai kolom mentah, tapi
        // review_status dimulai dari 'pending_review' — accessor
        // getStatusAttribute() di model Warranty akan menampilkan
        // 'pending_review' ke luar selama belum di-approve oleh
        // super_admin lewat panel Filament.
        $warranty = Warranty::create([
            'warranty_code'     => $warrantyCode,
            'customer_name'     => $request->customer_name,
            'phone_number'      => $request->phone_number,
            'car_plate'         => $request->car_plate,
            'car_type'          => $request->car_type,
            'product_series'    => $request->product_series,
            'installation_date' => $request->installation_date,
            'expiry_date'       => $request->expiry_date,
            'dealer_name'       => $request->dealer_name,
            'customer_id'       => $customerId,
            'status'            => 'active',
            'review_status'     => 'pending_review',
        ]);

        // CATATAN PENTING (per info resmi tim Ginnva China, akhir Juni
        // 2026): mereka belum bisa menyediakan API/data interface untuk
        // koneksi sistem realtime karena ketentuan pemerintah. Sinkronisasi
        // data warranty + after-sales + info pelanggan ke China sekarang
        // dilakukan lewat EXPORT EXCEL manual (mingguan/bulanan), dikirim
        // via email oleh tim Indonesia, BUKAN lewat API call. Export ada
        // di WarrantyResource (tombol "Export ke Excel"), bukan otomatis
        // dari sini.

        return response()->json([
            'message' => 'Data garansi berhasil didaftarkan dan sedang menunggu review admin.',
            'data' => $warranty,
        ], 201);
    }

    // GET /api/warranty/check?code=GNV-XXXXXXXXXX
    public function check(Request $request)
    {
        $code = $request->query('code');

        if (!$code) {
            return response()->json([
                'message' => 'Parameter "code" wajib diisi.',
            ], 422);
        }

        $warranty = Warranty::where('warranty_code', $code)
            ->orWhere('car_plate', $code)
            ->first();

        if (!$warranty) {
            return response()->json([
                'message' => 'Nomor garansi atau plat nomor tidak ditemukan.',
            ], 404);
        }

        // $warranty otomatis menyertakan remaining_days & status terkini
        // (termasuk 'pending_review' / 'rejected' bila relevan) karena
        // di-handle oleh accessor pada model Warranty.
        return response()->json([
            'success' => true,
            'data' => $warranty,
        ], 200);
    }

    // GET /api/warranty/download/{code}
    public function download($code)
    {
        $warranty = Warranty::where('warranty_code', $code)->firstOrFail();

        // E-warranty resmi hanya bisa diunduh setelah QA Certificate
        // disetujui oleh super_admin. Sebelum itu, dokumen belum sah.
        if ($warranty->review_status !== 'approved') {
            return response()->json([
                'message' => 'Sertifikat garansi ini belum disetujui dan belum bisa diunduh.',
            ], 403);
        }

        $pdf = Pdf::loadView('pdf.warranty_card', compact('warranty'))
                  ->setPaper('a4', 'portrait');

        return $pdf->download("E-Warranty-Ginnva-{$code}.pdf");
    }
}
