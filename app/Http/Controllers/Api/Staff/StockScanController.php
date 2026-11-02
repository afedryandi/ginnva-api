<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Support\StockScanCode;
use Illuminate\Http\Request;

/**
 * GET /api/staff/stock-scan?code=... — mengubah isi QR pada label rak/kemasan (lihat StockScanCode) menjadi tujuan layar di mobile:
 * Bahan Baku atau Barang Habis Pakai. Akun hanya dibawa ke menu yang memang boleh dibukanya.
 */
class StockScanController extends Controller
{
    public function lookup(Request $request)
    {
        $user = $request->user('api');
        $found = StockScanCode::resolve((string) $request->query('code', ''));

        if ($found === null) {
            return response()->json(['success' => false, 'message' => 'Kode tidak dikenali. Pastikan label berasal dari Bahan Baku atau Barang Habis Pakai.'], 404);
        }

        $isMaterial = $found['type'] === StockScanCode::TYPE_MATERIAL;

        if (! ($isMaterial ? $user->hasMaterialAccess() : $user->hasConsumableAccess())) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu ' . ($isMaterial ? 'Bahan Baku' : 'Barang Habis Pakai') . '.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'type' => $found['type'],
                'id' => $found['item']->getKey(),
                'name' => $found['item']->name,
            ],
        ]);
    }
}