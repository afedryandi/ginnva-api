<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\RawMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RawMaterialController extends Controller
{
    /**
     * Sama pola dengan InventoryController::authorizeScan() — dicek
     * lewat "Akses Menu" akun Filament staff (menu Bahan Baku).
     */
    private function authorizeAccess(Request $request): bool
    {
        $user = $request->user('api');

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(\App\Filament\Resources\RawMaterialResource::class);
    }

    /**
     * GET /api/staff/materials?search=...
     *
     * Bahan baku TIDAK punya kode fisik per unit (beda dari Barang/Aset
     * yang bisa di-scan QR) — jadi di app dicari lewat nama, bukan scan.
     * Dibatasi 20 hasil per pencarian, cukup untuk daftar pilih di app.
     */
    public function index(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.',
            ], 403);
        }

        $search = trim((string) $request->query('search', ''));

        $materials = RawMaterial::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'category', 'unit', 'current_stock', 'reorder_point']);

        return response()->json([
            'success' => true,
            'data' => $materials,
        ]);
    }

    /**
     * GET /api/staff/materials/{id}
     */
    public function show(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.',
            ], 403);
        }

        $material = RawMaterial::with(['movements' => fn ($q) => $q->with('user:id,name')->limit(20)])->find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Bahan baku tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $material,
        ]);
    }

    /**
     * POST /api/staff/materials/{id}/movement
     *
     * Catat masuk/keluar — BEDA dari inventory (Barang), di sini WAJIB
     * ada jumlah karena bahan baku dilacak per kuantitas, bukan per unit.
     */
    public function storeMovement(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:in,out',
            'quantity' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $material = RawMaterial::find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Bahan baku tidak ditemukan.',
            ], 404);
        }

        try {
            $material->recordMovement($request->type, (float) $request->quantity, $request->user('api')->id, $request->note);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $request->type === 'in' ? 'Bahan masuk berhasil dicatat.' : 'Bahan keluar berhasil dicatat.',
            'data' => $material->fresh(),
        ]);
    }
}
