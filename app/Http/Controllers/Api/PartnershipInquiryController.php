<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnershipInquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PartnershipInquiryController extends Controller
{
    /**
     * POST /api/partnership/submit
     *
     * Pengajuan kemitraan/franchise (我要加盟). SENGAJA publik (tidak
     * wajib login) — kalau request datang dengan token customer yang
     * valid, customer_id otomatis ikut tercatat; kalau tidak ada token
     * atau tokennya tidak valid, tetap diterima sebagai pengajuan guest.
     */
    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'applicant_name' => 'required|string|max:255',
            'phone_number'   => 'required|string|max:30',
            'email'          => 'nullable|email',
            'city'           => 'required|string|max:255',
            'message'        => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Coba ambil customer dari token kalau ada, tapi jangan gagalkan
        // request kalau token tidak ada/tidak valid — endpoint ini tetap
        // harus bisa diakses tanpa login sama sekali.
        $customer = null;
        try {
            $customer = $request->user('customer');
        } catch (\Throwable $e) {
            // diamkan — anggap guest
        }

        $inquiry = PartnershipInquiry::create([
            'customer_id'     => $customer?->id,
            'applicant_name'  => $request->applicant_name,
            'phone_number'    => $request->phone_number,
            'email'           => $request->email,
            'city'            => $request->city,
            'message'         => $request->message,
            'status'          => 'new',
        ]);

        return response()->json([
            'message' => 'Pengajuan kemitraan berhasil dikirim. Tim kami akan segera menghubungi Anda.',
            'data' => $inquiry,
        ], 201);
    }
}
