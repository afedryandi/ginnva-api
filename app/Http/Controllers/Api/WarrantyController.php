<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warranty;
use App\Jobs\SyncWarrantyToChina;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf; // Pastikan library dompdf sudah di-install

class WarrantyController extends Controller
{
    // POST /api/warranty/submit
    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [\
            'product_id' => 'required|string',\
            'store_id' => 'required|string',\
            'owner' => 'required|string',\
            'car_info' => 'required|array',\
            'install_date' => 'required|date',\
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $warrantyCode = 'GNV-' . strtoupper(Str::random(10));

        $warranty = Warranty::create([\
            'code' => $warrantyCode,\
            'product_id' => $request->product_id,\
            'store_id' => $request->store_id,\
            'owner' => $request->owner,\
            'car_info' => $request->car_info,\
            'install_date' => $request->install_date,\
            'status' => 'pending'\
        ]);

        SyncWarrantyToChina::dispatch($warranty);

        return response()->json([\
            'message' => 'Data garansi berhasil diterima dan sedang disinkronisasikan.',\
            'code' => $warrantyCode,\
            'status' => 'pending'\
        ], 202);
    }

    // GET /api/warranty/check/{code}
    public function check($code)
    {
        $warranty = Warranty::where('code', $code)->first();

        if (!$warranty) {
            return response()->json([\
                'message' => 'Nomor garansi tidak ditemukan.'\
            ], 404);
        }

        // Jika status masih pending/proses sinkronisasi belum selesai
        if ($warranty->status === 'pending') {
            return response()->json([\
                'status' => 'pending',\
                'message' => 'Data garansi terdaftar, namun sedang dalam proses sinkronisasi sistem.'\
            ], 200);
        }

        return response()->json([\
            'code' => $warranty->code,\
            'product_id' => $warranty->product_id,\
            'store_id' => $warranty->store_id,\
            'owner' => $warranty->owner,\
            'car_info' => is_string($warranty->car_info) ? json_decode($warranty->car_info, true) : $warranty->car_info,\
            'install_date' => $warranty->install_date,\
            'status' => $warranty->status\
        ], 200);
    }

    // GET /api/warranty/download/{code}
    public function download($code)
    {
        $warranty = Warranty::where('code', $code)->firstOrFail();
        $carInfo = is_string($warranty->car_info) ? json_decode($warranty->car_info, true) : $warranty->car_info;
    
        $pdf = Pdf::loadView('pdf.warranty_card', compact('warranty', 'carInfo'))
                  ->setPaper('a4', 'landscape');
    
        // UBAH DARI download() KE stream() UNTUK DEBUGS
        return $pdf->stream("E-Warranty-Ginnva-{$code}.pdf");
    }
}