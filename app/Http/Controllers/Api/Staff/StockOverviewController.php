<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use Illuminate\Http\Request;

/**
 * Ringkasan stok untuk Beranda aplikasi staf (audit Dashboard Inventaris, 2026-11-02): berapa bahan baku dan barang habis pakai yang menipis
 * atau mendekati kedaluwarsa, supaya staf gudang tahu apa yang perlu ditangani tanpa membuka daftar satu per satu. Hanya jumlah, tanpa nilai
 * rupiah. Tiap kategori hanya dikirim bila akun itu boleh membuka menunya (sama dengan menu di aplikasi); selain itu null.
 */
class StockOverviewController extends Controller
{
    /**
     * GET /api/staff/stock-overview
     */
    public function show(Request $request)
    {
        $user = $request->user('api');

        $materials = null;
        if ($user->hasMaterialAccess()) {
            $activeBatch = fn ($q) => $q->where('quantity', '>', 0)->whereNotNull('expiry_date');

            $materials = [
                'low' => RawMaterial::query()->active()->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point')->count(),
                'expired' => RawMaterial::query()->active()->whereHas('batches', fn ($q) => $activeBatch($q)->whereDate('expiry_date', '<', today()->toDateString()))->count(),
                // Mendekati = kedaluwarsa dalam 30 hari ke depan (yang sudah lewat dihitung di "expired").
                'near_expiry' => RawMaterial::query()->active()->whereHas('batches', fn ($q) => $activeBatch($q)
                    ->whereDate('expiry_date', '>=', today()->toDateString())
                    ->whereDate('expiry_date', '<=', today()->addDays(30)->toDateString()))->count(),
            ];
        }

        $consumables = null;
        if ($user->hasConsumableAccess()) {
            $consumables = [
                'low' => ConsumableItem::query()->active()->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point')->count(),
            ];
        }

        return response()->json(['success' => true, 'data' => ['materials' => $materials, 'consumables' => $consumables]]);
    }
}
