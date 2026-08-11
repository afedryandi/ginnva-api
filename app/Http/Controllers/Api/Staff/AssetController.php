<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AssetController extends Controller
{
    /**
     * Sama pola dengan InventoryController::authorizeScan() — dicek
     * lewat "Akses Menu" akun Filament staff (menu Aset), bukan role
     * hardcode.
     */
    private function authorizeScan(Request $request): bool
    {
        $user = $request->user('api');

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(\App\Filament\Resources\AssetResource::class);
    }

    /**
     * GET /api/staff/assets/{code}
     *
     * Scan QR aset — cari berdasarkan asset_tag, kembalikan detail +
     * daftar toko (untuk dropdown pindah lokasi di app).
     */
    public function show(Request $request, string $code)
    {
        if (! $this->authorizeScan($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Aset.',
            ], 403);
        }

        $asset = Asset::where('asset_tag', $code)
            ->with(['assignee:id,name', 'store:id,name'])
            ->first();

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => 'Aset dengan kode ini tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $asset,
            'stores' => Store::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * POST /api/staff/assets/{code}/update
     *
     * Ubah status dan/atau lokasi aset langsung dari app — dipakai staff
     * gudang/toko yang menerima/memindahkan aset fisik tanpa perlu buka
     * Filament. "Dipegang Oleh" SENGAJA tidak bisa diubah dari sini
     * (cuma dari Filament) supaya penetapan penanggung jawab tetap lewat
     * admin, bukan staff lapangan sembarang assign ke diri sendiri.
     */
    public function update(Request $request, string $code)
    {
        if (! $this->authorizeScan($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Aset.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:aktif,diperbaiki,rusak,dijual,hilang',
            'store_id' => 'nullable|exists:stores,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $asset = Asset::where('asset_tag', $code)->first();

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => 'Aset dengan kode ini tidak ditemukan.',
            ], 404);
        }

        $asset->update([
            'status' => $request->status,
            'store_id' => $request->filled('store_id') ? $request->store_id : $asset->store_id,
            'notes' => $request->filled('notes') ? $request->notes : $asset->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Aset berhasil diperbarui.',
            'data' => $asset->fresh(['assignee:id,name', 'store:id,name']),
        ]);
    }
}
