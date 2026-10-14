<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VehicleCatalogService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/vehicles/catalog — katalog merek/model kendaraan beserta ukuran (publik), untuk memilih kendaraan di aplikasi
 * (Fase 1 pengerjaan ulang booking, 2026-10-15). Lihat VehicleCatalogService.
 */
class VehicleCatalogController extends Controller
{
    public function index(VehicleCatalogService $catalog): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $catalog->options()]);
    }
}
