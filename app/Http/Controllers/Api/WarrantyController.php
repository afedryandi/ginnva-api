<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warranty;
use App\Jobs\SyncWarrantyToChina;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

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
            'status'            => 'active',
        ]);

        // Sinkronisasi ke sistem China dilakukan di background (queue),
        // supaya respons ke user tidak menunggu API China selesai.
        SyncWarrantyToChina::dispatch($warranty);

        return response()->json([
            'message' => 'Data garansi berhasil didaftarkan.',
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
        // karena di-handle oleh accessor pada model Warranty.
        return response()->json([
            'success' => true,
            'data' => $warranty,
        ], 200);
    }

    // GET /api/warranty/download/{code}
    public function download($code)
    {
        $warranty = Warranty::where('warranty_code', $code)->firstOrFail();

        $pdf = Pdf::loadView('pdf.warranty_card', compact('warranty'))
                  ->setPaper('a4', 'portrait');

        return $pdf->download("E-Warranty-Ginnva-{$code}.pdf");
    }
}